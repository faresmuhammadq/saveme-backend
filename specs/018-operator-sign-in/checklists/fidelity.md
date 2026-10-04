# Visual fidelity: Operator sign-in (T066)

Design source: artifact `Ag9pG4RnifekirczEG2drM`, version `1791137074-53df`, boards `SignIn` and `SignInAr` (read from the board source). Built step 2 compared in a browser (Chromium, 1280 x 720) in English and Arabic against the dev app.

| Aspect | Board | Built | Result |
|---|---|---|---|
| Layout | 600 px white brand panel with a hairline at its end, form centred in a 420 px column on the canvas colour | `w-150` panel, `border-e border-line`, `max-w-105` column on `bg-canvas`; panel hidden below `lg` | match |
| Brand panel | logo, "Save Me · Back office", two-ring graphic, headline "Quiet tools for careful decisions.", operators-only paragraph, audit note with shield | same, text from `admin::auth.brand.*`; product name comes from `config('app.name')` (P-13), so locally it reads "saveme-backend" | match, name by design |
| Step indicator | two accent segments, then "STEP 2 OF 2 · SECOND FACTOR" tracked uppercase | same (uppercase tracking dropped for Arabic by the base rule) | match |
| Heading, intro | "Enter your 6-digit code", "From the authenticator app linked to {email}. Not you?" | same; "Not you?" is a button in a small form (POST) styled as a link | match |
| Digit boxes | 56 x 64, six boxes, a dash between 3 and 4, accent border and 4 px ring on the focused box | `h-16 w-14`, same dash, focus ring from tokens; one `one_time_code` field behind them | match; token radius 10 px vs 12 px drawn |
| Countdown | ring icon and "Code refreshes in N s" | same, ticks from the server clock | match |
| Button | full width, 48 px, "Verify & sign in" | `size="lg"` primary | match |
| Links | recovery link accent at the start, "Lost your device?" muted at the end | same | match |
| Hint | tinted box with a key icon: "Re-enrolling a lost authenticator needs **a second operator** to approve. You have **8 of 10** recovery codes left." | "needs **two other operators** to approve" (Clarifications Q8, baseline wins); count in bold | match, wording change as decided |
| Focus ring, tokens | board tokens | the spec 006 token set | match |
| Arabic | `SignInAr`, mirrored | `dir="rtl"`; logo, panel and ring mirrored by logical utilities; digits, email and numbers left to right, Western digits | match |

Not on a board and composed from the primitives in the same split layout: step 1, recovery variant, lost device, new password, enrolment (setup key and link, no picture), recovery codes, security page, requests page, factor prompt.

A latent spec 006 problem surfaced here and was fixed in `admin.css`: a closed `<dialog>` with a display utility such as `flex` (the slide-over) showed itself. `dialog:not([open])` is now hidden.
