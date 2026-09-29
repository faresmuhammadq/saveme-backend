# Feature: Todo Application

A simple task management application where users can add, complete, filter, and delete todo items.

**Target application**: https://demo.playwright.dev/todomvc

---

### User Story 1: Add a todo item

As a user, I want to add new todo items so that I can track tasks I need to complete.

**Acceptance Scenarios**:

1. Given the user is on https://demo.playwright.dev/todomvc, When the user types "Buy groceries" into the "What needs to be done?" field, When the user presses the Enter key, Then the page should display "Buy groceries" in the todo list, Then the input field should be empty

2. Given the user is on https://demo.playwright.dev/todomvc, When the user types "Read a book" into the "What needs to be done?" field, When the user presses the Enter key, When the user types "Call dentist" into the "What needs to be done?" field, When the user presses the Enter key, Then the page should display "Read a book", Then the page should display "Call dentist", Then the page should display "2 items left"

---

### User Story 2: Complete a todo item

As a user, I want to mark todo items as complete so that I can track my progress.

**Acceptance Scenarios**:

1. Given the user is on https://demo.playwright.dev/todomvc, When the user types "Write tests" into the "What needs to be done?" field, When the user presses the Enter key, When the user clicks the toggle checkbox next to "Write tests", Then the todo item "Write tests" should appear with completed styling, Then the page should display "0 items left"

2. Given the user is on https://demo.playwright.dev/todomvc, When the user types "Task one" into the "What needs to be done?" field, When the user presses the Enter key, When the user types "Task two" into the "What needs to be done?" field, When the user presses the Enter key, When the user clicks the toggle checkbox next to "Task one", Then the page should display "1 item left"

---

### User Story 3: Filter todo items

As a user, I want to filter todos by status so that I can focus on active or completed items.

**Acceptance Scenarios**:

1. Given the user is on https://demo.playwright.dev/todomvc, When the user types "Active item" into the "What needs to be done?" field, When the user presses the Enter key, When the user types "Done item" into the "What needs to be done?" field, When the user presses the Enter key, When the user clicks the toggle checkbox next to "Done item", When the user clicks "Active", Then the page should display "Active item", Then the page should not display "Done item"

2. Given the user is on https://demo.playwright.dev/todomvc, When the user types "Pending task" into the "What needs to be done?" field, When the user presses the Enter key, When the user types "Finished task" into the "What needs to be done?" field, When the user presses the Enter key, When the user clicks the toggle checkbox next to "Finished task", When the user clicks "Completed", Then the page should display "Finished task"

---

### User Story 4: Clear completed todos

As a user, I want to remove all completed todos at once so that I can keep my list clean.

**Acceptance Scenarios**:

1. Given the user is on https://demo.playwright.dev/todomvc, When the user types "Done task" into the "What needs to be done?" field, When the user presses the Enter key, When the user types "Open task" into the "What needs to be done?" field, When the user presses the Enter key, When the user clicks the toggle checkbox next to "Done task", When the user clicks "Clear completed", Then the page should not display "Done task", Then the page should display "Open task", Then the page should display "1 item left"
