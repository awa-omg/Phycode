package app

import (
	"os"
	"path/filepath"
	"reflect"
	"runtime"
	"slices"
	"strings"
	"testing"

	"phycode/tui/internal/state"
)

func fakeEditor(t *testing.T, body string) string {
	t.Helper()
	if runtime.GOOS == "windows" {
		t.Skip("the fake editor is a shell script")
	}
	path := filepath.Join(t.TempDir(), "editor.sh")
	if err := os.WriteFile(path, []byte("#!/bin/sh\n"+body+"\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	return path
}

func runEditor(t *testing.T, ed openEditor) (string, editorDone, error) {
	t.Helper()
	cmd, finish, err := newEditSession(ed)
	if err != nil {
		t.Fatalf("newEditSession: %v", err)
	}
	file := cmd.Args[len(cmd.Args)-1]
	runErr := cmd.Run()
	return file, finish(runErr), runErr
}

func TestTheEditorEditsTheComposerTextThroughATemporaryFile(t *testing.T) {
	script := fakeEditor(t, `printf '\nsecond line' >> "$1"`)
	file, done, err := runEditor(t, openEditor{Editor: script, Text: "first line"})
	if err != nil {
		t.Fatalf("the editor failed: %v", err)
	}
	if !done.Temp || done.Err != nil || done.Text != "first line\nsecond line" {
		t.Errorf("result = %+v, want the composer text with the line the editor added", done)
	}
	if _, statErr := os.Stat(file); !os.IsNotExist(statErr) {
		t.Errorf("the temporary file %s was left behind (%v)", file, statErr)
	}
}

func TestTheComposerTakesTheEditedText(t *testing.T) {
	script := fakeEditor(t, `printf '\nsecond line' >> "$1"`)
	_, done, _ := runEditor(t, openEditor{Editor: script, Text: "first line"})
	m := editorFinished(state.Model{Input: "old"}, done)
	if m.Input != "first line\nsecond line" {
		t.Errorf("Input = %q, want the text the editor left", m.Input)
	}
}

func TestAProjectFileIsEditedAndTheComposerIsLeftAlone(t *testing.T) {
	script := fakeEditor(t, `printf '\nsecond line' >> "$1"`)
	file := filepath.Join(t.TempDir(), "notes.md")
	if err := os.WriteFile(file, []byte("first line"), 0o644); err != nil {
		t.Fatal(err)
	}
	_, done, err := runEditor(t, openEditor{Editor: script, Path: file})
	if err != nil {
		t.Fatalf("the editor failed: %v", err)
	}
	if done.Temp || done.Err != nil {
		t.Errorf("result = %+v, want a project file with no text taken back", done)
	}
	if data, _ := os.ReadFile(file); string(data) != "first line\nsecond line" {
		t.Errorf("the file holds %q, want the line the editor added", data)
	}
	if m := editorFinished(state.Model{Input: "draft"}, done); m.Input != "draft" {
		t.Errorf("Input = %q, want the composer untouched", m.Input)
	}
}

func TestAnEditorThatCannotStartKeepsTheComposer(t *testing.T) {
	missing := filepath.Join(t.TempDir(), "no-such-editor")
	file, done, err := runEditor(t, openEditor{Editor: missing, Text: "draft"})
	if err == nil {
		t.Fatal("a missing editor ran")
	}
	if !done.Temp || done.Err == nil || done.Text != "draft" {
		t.Errorf("result = %+v, want the failure with the text the file held", done)
	}
	if m := editorFinished(state.Model{Input: "draft"}, done); m.Input != "draft" || m.Flash == "" {
		t.Errorf("Input = %q, Flash = %q; want the composer kept and the failure said", m.Input, m.Flash)
	}
	if _, statErr := os.Stat(file); !os.IsNotExist(statErr) {
		t.Errorf("the temporary file was left behind (%v)", statErr)
	}
}

func TestAnEditorThatExitsWithAFailureKeepsTheComposer(t *testing.T) {
	script := fakeEditor(t, `printf 'changed' >> "$1"; exit 3`)
	_, done, err := runEditor(t, openEditor{Editor: script, Text: "draft"})
	if err == nil || done.Err == nil {
		t.Fatalf("err = %v, result = %+v; want the non-zero exit reported", err, done)
	}
	if m := editorFinished(state.Model{Input: "draft"}, done); m.Input != "draft" {
		t.Errorf("Input = %q, want the composer kept after a failed exit", m.Input)
	}
}

func TestTheEditorCommandMayCarryArguments(t *testing.T) {
	cmd := editorCommand("sh -c true", "/tmp/a.md")
	if want := []string{"sh", "-c", "true", "/tmp/a.md"}; !reflect.DeepEqual(cmd.Args, want) {
		t.Errorf("Args = %v, want %v", cmd.Args, want)
	}
}

func TestTheEditorGetsNoKeysOrTokens(t *testing.T) {
	for _, name := range []string{"OPENAI_API_KEY", "ANTHROPIC_API_KEY", "GITHUB_TOKEN", "PHYCODE_TOKEN"} {
		t.Setenv(name, "secret")
	}
	t.Setenv("HOME", "/home/tester")
	cmd := editorCommand("nano", "/tmp/a.md")
	if cmd.Env == nil {
		t.Fatal("the editor inherits the whole environment of the session")
	}
	if !slices.Contains(cmd.Env, "HOME=/home/tester") {
		t.Errorf("the editor does not get HOME: %d variables", len(cmd.Env))
	}
	for _, entry := range cmd.Env {
		if name, _, _ := strings.Cut(entry, "="); strings.HasSuffix(name, "_API_KEY") || strings.HasSuffix(name, "_TOKEN") {
			t.Errorf("the editor gets %s", name)
		}
	}
}

func TestTheEditorIsTheConfigurationThenEDITORThenNano(t *testing.T) {
	t.Setenv("EDITOR", "vim -u NONE")
	if got := resolveEditor("code --wait"); got != "code --wait" {
		t.Errorf("configured editor = %q, want code --wait", got)
	}
	if got := resolveEditor("  "); got != "vim -u NONE" {
		t.Errorf("without a configured editor = %q, want $EDITOR", got)
	}
	t.Setenv("EDITOR", "")
	if got := resolveEditor(""); got != "nano" {
		t.Errorf("with neither set = %q, want nano", got)
	}
}
