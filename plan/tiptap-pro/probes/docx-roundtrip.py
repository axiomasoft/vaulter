"""Generate a synthetic DOCX and compare semantics before and after an office save."""

import argparse
import json
from pathlib import Path, PurePosixPath
import posixpath
import subprocess
from tempfile import TemporaryDirectory
from xml.etree import ElementTree as ET
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED

W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
R = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
PR = "http://schemas.openxmlformats.org/package/2006/relationships"
CT = "http://schemas.openxmlformats.org/package/2006/content-types"
W14 = "http://schemas.microsoft.com/office/word/2010/wordml"
W15 = "http://schemas.microsoft.com/office/word/2012/wordml"
MC = "http://schemas.openxmlformats.org/markup-compatibility/2006"
V = "urn:vaulter:lab:review:1"
NS = {"w": W, "r": R, "w14": W14, "w15": W15}


def attr(namespace, key):
    return f"{{{namespace}}}{key}"


def part(element, content):
    return f'<w:{element} xmlns:w="{W}" xmlns:r="{R}">{content}</w:{element}>'


def paragraph(text):
    return f"<w:p><w:r><w:t>{text}</w:t></w:r></w:p>"


def relationship_document(rows):
    links = "".join(f'<Relationship Id="{key}" Type="{kind}" Target="{dest}"/>' for key, kind, dest in rows)
    return f'<Relationships xmlns="{PR}">{links}</Relationships>'


def fixture(path):
    files = {
        "_rels/.rels": relationship_document([("rDoc", f"{R}/officeDocument", "word/document.xml")]),
        "word/settings.xml": part("settings", "<w:evenAndOddHeaders/>"),
        "word/header1.xml": part("hdr", paragraph("Section 1 default header")),
        "word/header2.xml": part("hdr", paragraph("Section 1 even header")),
        "word/header3.xml": part("hdr", paragraph("Section 2 default header")),
        "word/header4.xml": part("hdr", paragraph("Section 2 first header")),
        "word/footer1.xml": part("ftr", paragraph("Shared default footer")),
        "word/comments.xml": f'''<w:comments xmlns:w="{W}" xmlns:w14="{W14}" xmlns:mc="{MC}" mc:Ignorable="w14">
          <w:comment w:id="7" w:author="Synthetic Reviewer"><w:p w14:paraId="1234ABCD"><w:r><w:t>Root comment</w:t></w:r></w:p></w:comment>
          <w:comment w:id="8" w:author="Synthetic Editor"><w:p w14:paraId="1234ABCE"><w:r><w:t>Reply comment</w:t></w:r></w:p></w:comment>
        </w:comments>''',
        "word/commentsExtended.xml": f'''<w15:commentsEx xmlns:w15="{W15}">
          <w15:commentEx w15:paraId="1234ABCD" w15:done="1"/>
          <w15:commentEx w15:paraId="1234ABCE" w15:paraIdParent="1234ABCD"/>
        </w15:commentsEx>''',
        "customXml/item1.xml": f'<review xmlns="{V}"><replacement deletion="11" insertion="12"/></review>',
    }
    first_section = '''<w:sectPr>
        <w:headerReference w:type="default" r:id="rH1"/><w:headerReference w:type="even" r:id="rH2"/>
        <w:footerReference w:type="default" r:id="rF1"/>
        <w:type w:val="nextPage"/><w:pgSz w:w="11906" w:h="16838"/>
        </w:sectPr>'''
    second_section = '''<w:sectPr>
        <w:headerReference w:type="default" r:id="rH3"/><w:headerReference w:type="first" r:id="rH4"/>
        <w:pgSz w:w="11906" w:h="16838"/><w:titlePg/>
        </w:sectPr>'''
    table = """<w:tbl><w:tblPr><w:tblW w:w="5400" w:type="dxa"/></w:tblPr>
        <w:tblGrid><w:gridCol w:w="1800"/><w:gridCol w:w="1800"/><w:gridCol w:w="1800"/></w:tblGrid>
        <w:tr><w:trPr><w:tblHeader/></w:trPr>
        <w:tc><w:tcPr><w:tcW w:w="3600" w:type="dxa"/><w:gridSpan w:val="2"/><w:vMerge w:val="restart"/></w:tcPr>
        <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Bold merged cell</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Row 1 side cell</w:t></w:r></w:p></w:tc></w:tr>
        <w:tr><w:tc><w:tcPr><w:tcW w:w="3600" w:type="dxa"/><w:gridSpan w:val="2"/><w:vMerge/></w:tcPr><w:p/></w:tc>
        <w:tc><w:p><w:r><w:t>Row 2 side cell</w:t></w:r></w:p></w:tc></w:tr></w:tbl>"""
    review = """<w:p><w:commentRangeStart w:id="7"/><w:r><w:t>Annotated clause</w:t></w:r>
        <w:commentRangeEnd w:id="7"/><w:r><w:commentReference w:id="7"/></w:r></w:p>
        <w:p><w:r><w:t xml:space="preserve">Payment term </w:t></w:r>
        <w:del w:id="11" w:author="Synthetic Reviewer"><w:r><w:delText>15</w:delText></w:r></w:del>
        <w:ins w:id="12" w:author="Synthetic Reviewer"><w:r><w:t>30</w:t></w:r></w:ins></w:p>
        <w:p><w:r><w:rPr><w:b/><w:rPrChange w:id="13" w:author="Synthetic Editor"><w:rPr/></w:rPrChange></w:rPr>
        <w:t>Formatting proposal</w:t></w:r></w:p>"""
    files["word/document.xml"] = part("document", f"""<w:body>{paragraph('Section one')}
        {table}<w:p><w:pPr>{first_section}</w:pPr></w:p>
        {paragraph('Section two')}<w:p><w:r><w:br w:type="page"/></w:r></w:p>{review}
        {second_section}</w:body>""")
    relationships = [("rSettings", f"{R}/settings", "settings.xml"), ("rComments", f"{R}/comments", "comments.xml"),
                     ("rThreads", "http://schemas.microsoft.com/office/2011/relationships/commentsExtended", "commentsExtended.xml"),
                     ("rReview", f"{R}/customXml", "../customXml/item1.xml"), ("rF1", f"{R}/footer", "footer1.xml")]
    relationships.extend((f"rH{i}", f"{R}/header", f"header{i}.xml") for i in range(1, 5))
    files["word/_rels/document.xml.rels"] = relationship_document(relationships)
    mime = "application/vnd.openxmlformats-officedocument.wordprocessingml"
    content_types = {
        "document.xml": f"{mime}.document.main+xml", "settings.xml": f"{mime}.settings+xml",
        "comments.xml": f"{mime}.comments+xml", "commentsExtended.xml": "application/vnd.ms-word.commentsExtended+xml",
        "footer1.xml": f"{mime}.footer+xml",
        **{f"header{i}.xml": f"{mime}.header+xml" for i in range(1, 5)},
    }
    overrides = "".join(f'<Override PartName="/word/{name}" ContentType="{kind}"/>' for name, kind in content_types.items())
    files["[Content_Types].xml"] = f'''<Types xmlns="{CT}">
        <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
        <Default Extension="xml" ContentType="application/xml"/>{overrides}</Types>'''
    with ZipFile(path, "w") as archive:
        for name, content in sorted(files.items()):
            archive.writestr(ZipInfo(name), content, compress_type=ZIP_DEFLATED)


def inspect(path):
    def text(root, kind="t"):
        return "".join(node.text or "" for node in root.iter(attr(W, kind)))

    with ZipFile(path) as archive:
        names = set(archive.namelist())

        def xml(name):
            return ET.fromstring(archive.read(name))

        document = xml("word/document.xml")
        targets = {}
        for link in xml("word/_rels/document.xml.rels"):
            if link.get("TargetMode") != "External":
                dest = link.attrib["Target"]
                targets[link.attrib["Id"]] = dest.lstrip("/") if dest.startswith("/") else posixpath.normpath(f"word/{dest}")

        table = document.find(".//w:tbl", NS)
        sections = []
        inherited = {}
        for sect in document.findall(".//w:sectPr", NS):
            explicit = {}
            for element in sect:
                local = element.tag.split("}")[-1]
                if local in ("headerReference", "footerReference"):
                    dest = targets[element.attrib[attr(R, "id")]]
                    explicit[f"{local[:-9]}.{element.attrib[attr(W, 'type')]}"] = text(xml(dest))
            inherited.update(explicit)
            sections.append({"explicit": explicit, "effective_refs": dict(inherited),
                             "first_page_variant": sect.find("w:titlePg", NS) is not None})

        def revisions(name, text_element):
            return sorted((text(node, text_element), node.get(attr(W, "author"), ""))
                          for node in document.findall(f".//w:{name}", NS))

        ranges = [element.attrib[attr(W, "id")] for element in document.findall(".//w:commentRangeStart", NS)]
        paragraph_ids = {}
        comments = []
        if "word/comments.xml" in names:
            for comment in xml("word/comments.xml"):
                value = text(comment)
                paragraphs = comment.findall(".//w:p", NS)
                if paragraphs and (tag := paragraphs[-1].get(attr(W14, "paraId"))):
                    paragraph_ids[tag] = value
                comments.append({"text": value, "author": comment.get(attr(W, "author")),
                                 "anchored": comment.get(attr(W, "id")) in ranges})

        threads = []
        if "word/commentsExtended.xml" in names:
            for extra in xml("word/commentsExtended.xml"):
                para_id = extra.get(attr(W15, "paraId"))
                parent = extra.get(attr(W15, "paraIdParent"))
                threads.append({"comment": paragraph_ids.get(para_id), "reply_to": paragraph_ids.get(parent),
                                "done": extra.get(attr(W15, "done"), "0") in ("1", "true")})

        groups = []
        for dest in targets.values():
            if PurePosixPath(dest).parts[0] == "customXml" and dest in names:
                groups.extend(xml(dest).findall(f"{{{V}}}replacement"))
        ids = {node.get(attr(W, "id")) for tag in ("w:ins", "w:del") for node in document.findall(f".//{tag}", NS)}
        return {
            "table_rows": len(table.findall("w:tr", NS)),
            "table_cols": len(table.findall("w:tblGrid/w:gridCol", NS)),
            "col_widths_dxa": [int(node.attrib[attr(W, "w")]) for node in table.findall("w:tblGrid/w:gridCol", NS)],
            "table_cells": [text(cell) for cell in table.findall("w:tr/w:tc", NS)],
            "header_rows": len(table.findall("w:tr/w:trPr/w:tblHeader", NS)),
            "table_spans": [node.attrib[attr(W, "val")] for node in table.findall(".//w:gridSpan", NS)],
            "table_merges": [node.get(attr(W, "val"), "continue") for node in table.findall(".//w:vMerge", NS)],
            "bold_in_merged_cell": any(node.find("w:rPr/w:b", NS) is not None and text(node) == "Bold merged cell"
                                       for node in table.findall(".//w:r", NS)),
            "sections": sections,
            "explicit_page_breaks": sum(node.get(attr(W, "type")) == "page" for node in document.findall(".//w:br", NS)),
            "comments": comments,
            "threads": threads,
            "insertions": revisions("ins", "t"),
            "deletions": revisions("del", "delText"),
            "format_revisions": len(document.findall(".//w:rPrChange", NS)),
            "replacement_metadata_parts": len(groups),
            "valid_replacement_references": sum(set(group.attrib.values()) <= ids for group in groups),
        }


def run(office):
    with TemporaryDirectory(prefix="vaulter-docx-") as temporary:
        work = Path(temporary)
        original = work / "synthetic-office.docx"
        fixture(original)
        initial = inspect(original)
        assert initial["table_spans"] == ["2", "2"] and initial["table_merges"] == ["restart", "continue"]
        assert initial["sections"][1]["effective_refs"]["footer.default"] == "Shared default footer"
        assert any(item["reply_to"] == "Root comment" for item in initial["threads"])
        assert initial["format_revisions"] == 1 and initial["valid_replacement_references"] == 1

        output = work / "resaved"
        output.mkdir()
        subprocess.run([office, f"-env:UserInstallation={(work / 'profile').as_uri()}", "--headless",
                        "--convert-to", "docx:Office Open XML Text", "--outdir", str(output), str(original)],
                       check=True, capture_output=True, timeout=45)
        saved = output / original.name
        if not saved.exists():
            raise RuntimeError("LibreOffice did not write the DOCX")
        after = inspect(saved)
        preserved = [key for key in initial if after[key] == initial[key]]
        return {"office": subprocess.check_output([office, "--version"], text=True, timeout=15).strip(),
                "comparison": {"identical_properties": preserved, "changed_properties": [
                    key for key in initial if key not in preserved
                ]}, "input": initial, "saved": after}


if __name__ == "__main__":
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("--office", default="libreoffice")
    cli.add_argument("--report", type=Path)
    cli.add_argument("--fixture", type=Path, help="write only the canonical synthetic input DOCX")
    args = cli.parse_args()
    if args.fixture:
        fixture(args.fixture)
    else:
        report = json.dumps(run(args.office), indent=2) + "\n"
        if args.report:
            args.report.write_text(report)
        print(report, end="")
