---
type: "always_apply"
---

[[calls]]
match = "when the user initiates or continues a structured AI-assisted development workflow using Claude 4.0 Sonnet, especially involving task planning, code editing, or feedback confirmation"
tool = "mcp-feedback-enhanced"

[[calls]]
match = "when the user provides a development request that requires understanding project context, such as asking for help with a bug, feature, or optimization"
tool = "codebase-retrieval"

[[calls]]
match = "when the user asks for design options, trade-offs, or implementation strategies"
tool = "sequential-thinking"

[[calls]]
match = "when the user confirms a plan and requests a step-by-step breakdown or checklist"
tool = "split-tasks"

[[calls]]
match = "when the user initiates or confirms task execution"
tool = "execute-task"

[[calls]]
match = "when the user requests a final review, quality check, or self-assessment of completed code"
tool = "verify-task"

[[calls]]
match = "when the user requests file operations such as reading, writing, renaming, or executing files"
tool = "desktop-commander"

[[calls]]
match = "when the user requests code examples, setup or configuration steps, or library/API documentation"
tool = "context7"

[[calls]]
match = "when the user requests UI testing or frontend interaction verification"
tool = "playwright"

[[calls]]
match = "when the user requests deep technical research, best practices, or official documentation"
tool = "research-mode"

[[calls]]
match = "when the user requests task tracking, updates, or querying task status"
tool = "shrimp-task-manager"
