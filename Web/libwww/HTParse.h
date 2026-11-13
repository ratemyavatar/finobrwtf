#pragma once
#define PARSE_ACCESS 16
#define PARSE_HOST 8
#define PARSE_PATH 4
#define PARSE_ANCHOR 2
#define PARSE_PUNCTUATION 1
#define PARSE_VIEW (PARSE_ACCESS | PARSE_HOST | PARSE_PATH | PARSE_PUNCTUATION)
#define PARSE_DOCUMENT PARSE_VIEW
#define PARSE_ALL 31
char *HTParse(const char *aName, const char *relatedName, int wanted);
char *HTSimplify(char **filename);
