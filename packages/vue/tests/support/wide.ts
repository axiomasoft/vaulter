/**
 * A JSON import types every string as `string`, so it can never be assigned to a schema type
 * with an enum. `Wide<T>` widens literals to their primitive while keeping every key,
 * nullability and nesting: assigning an example to `Wide<Node>` fails when a key is missing,
 * misspelled or has the wrong primitive type. Enum values are asserted at runtime.
 */
export type Wide<T> =
  T extends string ? string
    : T extends number ? number
      : T extends boolean ? boolean
        : T extends null ? null
          : T extends Array<infer U> ? Array<Wide<U>>
            : T extends object ? { [K in keyof T]: Wide<T[K]> }
              : T
