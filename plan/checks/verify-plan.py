"""Validate phase cards and, optionally, one submitted audit report. Python 3.9+."""

import argparse
import json
import re
import sys
from graphlib import CycleError, TopologicalSorter
from pathlib import Path


SPEC = Path(__file__).resolve().parents[1]
PHASES = (
    "00-baseline", "01-documents", "02-collaboration", "03-text-sdk",
    "04-sheets", "05-sheet-sdk", "06-extensions", "07-agent-workspace",
)
TASK = r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
GATE = rf"{TASK}-[A-Z][A-Z0-9]*"
STATES = {"pass", "fail", "not_run", "disabled"}
EXCHANGE_FORMATS = {"4.3-A": "xlsx", "4.3-B": "csv"}


def require(test, message):
    if not test:
        raise ValueError(message)


def read_phases():
    index = (SPEC / "README.md").read_text()
    linked = {link.removeprefix("./") for link in re.findall(r"\]\(([^\s)#]+)(?:#[^)]*)?\)", index)}
    phases = sorted((SPEC / "phases").glob("*.md"))
    require([p.stem for p in phases] == list(PHASES), "файлы фаз должны соответствовать фазам 00–07")
    dependencies, gates = {}, {}
    for phase in phases:
        require(f"phases/{phase.name}" in linked, f"README не ссылается на {phase.name}")
        phase_number = int(phase.name[:2])
        task, waiting, fence = None, False, None
        for i, raw in enumerate(phase.read_text().splitlines(), 1):
            line = raw.strip()
            where = f"{phase.name}:{i}"
            if not line:
                continue
            if fence:
                if re.fullmatch(re.escape(fence[0]) + "{" + str(len(fence)) + ",}", line):
                    fence = None
                continue
            if waiting:
                match = re.fullmatch(rf"\*\*После\*\* — (нет|{TASK}(?:, {TASK})*)\.", line)
                require(match, f"{where}: ожидается строка зависимостей задачи {task}")
                deps = [] if match[1] == "нет" else match[1].split(", ")
                require(len(set(deps)) == len(deps), f"{where}: повтор зависимости")
                dependencies[task] = deps
                waiting = False
                continue
            opening = re.match(r"^(`{3,}|~{3,})", line)
            if opening:
                fence = opening[1]
                continue
            if line.startswith("### "):
                match = re.fullmatch(rf"### ({TASK}) \S.*", line)
                require(match, f"{where}: заголовок должен содержать ID задачи")
                task = match[1]
                require(int(task.split(".")[0]) == phase_number, f"{where}: задача другой фазы")
                require(task not in gates, f"{where}: повтор задачи {task}")
                gates[task] = []
                waiting = True
            elif line.startswith("- **") and re.match(r"- \*\*\d", line):
                match = re.fullmatch(rf"- \*\*({GATE})\*\* \S.*", line)
                require(match, f"{where}: неверный формат критерия")
                gate = match[1]
                require(task == gate.split("-")[0], f"{where}: критерий чужой задачи")
                require(gate not in gates[task], f"{where}: повтор {gate}")
                gates[task].append(gate)
            elif line.startswith("**После**"):
                raise ValueError(f"{where}: лишняя строка зависимостей")
        require(task and not waiting and not fence, f"{phase.name}: незавершённая карточка")
    for task, deps in dependencies.items():
        require(gates[task], f"{task}: отсутствует критерий")
        for dep in deps:
            require(dep in dependencies, f"{task}: неизвестная зависимость {dep}")
            require(dep != "4.3", f"{task}: обязательная зависимость от условной задачи 4.3")
    require(set(gates.get("4.3", [])) == set(EXCHANGE_FORMATS),
            "критерии 4.3 должны соответствовать форматам профиля обмена")
    try:
        tuple(TopologicalSorter(dependencies).static_order())
    except CycleError as exc:
        raise ValueError(f"цикл: {' → '.join(exc.args[1])}") from exc
    return gates


def text_field(value):
    return isinstance(value, str) and bool(value.strip())


def object_fields(value, names, label):
    require(isinstance(value, dict) and set(value) == set(names.split()), f"{label}: ожидаются поля {names}")


def unique_keys(pairs):
    obj = {}
    for key, value in pairs:
        require(key not in obj, f"повтор ключа JSON {key}")
        obj[key] = value
    return obj


def check_report(path, gates):
    data = json.loads(path.read_text(), object_pairs_hook=unique_keys)
    task = data.get("task") if isinstance(data, dict) else None
    fields = "version task repos commands gates"
    object_fields(data, fields + " exchange_formats" if task == "4.3" else fields, "отчёт")
    require(type(data["version"]) is int and data["version"] == 1, "неизвестная версия отчёта")
    require(text_field(task) and task in gates, "неизвестная задача отчёта")
    exchange_formats = data.get("exchange_formats", [])
    if task == "4.3":
        require(isinstance(exchange_formats, list) and
                all(text_field(fmt) and fmt in EXCHANGE_FORMATS.values() for fmt in exchange_formats) and
                len(exchange_formats) == len(set(exchange_formats)),
                "exchange_formats: ожидается список заказанных форматов xlsx и/или csv без повторов")
    repos = data["repos"]
    require(isinstance(repos, dict) and repos, "repos: нужен репозиторий и полная ревизия")
    for name, sha in repos.items():
        require(text_field(name) and text_field(sha) and re.fullmatch(r"(?:[a-f0-9]{40}|[a-f0-9]{64})", sha),
                f"repos.{name}: нужна полная Git SHA")
    commands = {}
    require(isinstance(data["commands"], list), "commands: ожидается массив")
    for entry in data["commands"]:
        object_fields(entry, "id repo command exit_code output", "команда")
        key = entry["id"]
        require(text_field(key) and key not in commands, "пустой или повторный ID команды")
        require(text_field(entry["repo"]) and entry["repo"] in repos, f"{key}: неизвестный репозиторий")
        require(text_field(entry["command"]) and text_field(entry["output"]), f"{key}: нужны команда и вывод")
        require(type(entry["exit_code"]) is int, f"{key}: нужен численный exit_code")
        commands[key] = entry
    rows = data["gates"]
    object_fields(rows, " ".join(gates[task]), "критерии задачи")
    states = set()
    for gate, row in rows.items():
        object_fields(row, "state actual evidence", gate)
        state, evidence = row["state"], row["evidence"]
        require(text_field(state) and state in STATES, f"{gate}: недопустимый state")
        if state == "disabled":
            require(gate in EXCHANGE_FORMATS and EXCHANGE_FORMATS[gate] not in exchange_formats,
                    f"{gate}: disabled допускается только для незаказанного формата")
        if gate in EXCHANGE_FORMATS and state == "pass":
            require(EXCHANGE_FORMATS[gate] in exchange_formats,
                    f"{gate}: pass требует заказанного и проверенного формата")
        require(text_field(row["actual"]), f"{gate}: ожидается измеренный исход либо причина пропуска")
        require(isinstance(evidence, list) and all(text_field(x) and x in commands for x in evidence),
                f"{gate}: evidence должен ссылаться на команды отчёта")
        if state == "not_run":
            require(not evidence, f"{gate}: непроверенный критерий отмечен измеренным")
        else:
            require(evidence, f"{gate}: для {state} нет свидетельства")
            if state in {"pass", "disabled"}:
                require(all(commands[x]["exit_code"] == 0 for x in evidence),
                        f"{gate}: для {state} нужны успешные команды проверки")
        states.add(state)
    if "fail" in states:
        return task, "fail"
    if "not_run" in states or (task == "4.3" and not exchange_formats):
        return task, "partial"
    return task, "pass"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report", type=Path, help="проверить отчёт одной задачи")
    args = parser.parse_args()
    try:
        gates = read_phases()
        if args.report:
            task, state = check_report(args.report, gates)
            print(f"{task} {state}")
            return {"pass": 0, "fail": 1, "partial": 3}[state]
        print(f"OK: фаз {len(PHASES)}, задач {len(gates)}, критериев {sum(map(len, gates.values()))}")
        return 0
    except (OSError, ValueError) as exc:
        print(f"invalid: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
