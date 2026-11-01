# Security

## Supported versions

Only the latest release gets security fixes.

## Reporting a problem

Please do not open a public issue. Email wolfgang@wazum.com instead, with a DOT
file or a short PHP script that shows the problem.

## What php-dag protects against

php-dag is meant to render graphs from sources you do not fully trust, such as
a DOT file from another tool:

- Control characters in titles, labels and ids are stripped by the DOT parser
  and rejected by the `Graph` API, so a file cannot send terminal escape
  sequences through the output.
- Syntax errors throw `DotSyntaxException` with a line and column; the parser
  does not execute or include anything.

It does not limit the size of a graph. A very large or very dense graph can
take a lot of time and memory (see [Performance](guide/performance.md)). If you
render graphs from strangers, limit the input size and PHP's `memory_limit` and
`max_execution_time` yourself.
