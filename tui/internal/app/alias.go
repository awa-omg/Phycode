package app

import "strings"

var aliases = map[string]string{
	"/new": "/clear", "/summarize": "/compact", "/resume": "/sessions", "/continue": "/sessions", "/themes": "/theme", "/q": "/exit",
}

func resolveAlias(line string) string {
	word, rest, found := strings.Cut(line, " ")
	if to, ok := aliases[word]; ok {
		if found {
			return to + " " + rest
		}
		return to
	}
	return line
}
