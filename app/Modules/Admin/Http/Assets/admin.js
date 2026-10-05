/*
 * Back-office behaviour (spec 006). Alpine ships with Livewire; nothing is
 * imported here. Each component is small and stateless on the server: the
 * Blade primitive renders the markup, these add keyboard and focus rules.
 *
 *   adminMenu        APG menu button (arrows, Home/End, Enter, Escape, typeahead)
 *   adminSelect      APG select-only combobox
 *   adminDatePicker  calendar grid with Western digits
 *   adminMoneyInput  major-unit text ↔ integer minor units, string maths only
 *   adminDialog      native <dialog> open/close with focus return
 *   adminCopy        copy to clipboard with a short confirmation
 *   adminToasts      the toast stack
 *   adminCodeInput   six digit boxes over one `one_time_code` field (spec 018, FR-092)
 *   adminCountdown   seconds until the next 30-second code, from the server's clock
 *   adminDownload    saves text as a file, client-side
 *   (livewire:init)  a 401 from a back-office component sends the browser to sign-in
 */

const focusable = (root, selector) => [...root.querySelectorAll(selector)].filter((el) => el.getAttribute('aria-disabled') !== 'true' && ! el.disabled);

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.data('adminMenu', (initiallyOpen = false) => ({
        open: initiallyOpen,
        typed: '',
        typedAt: 0,

        items() {
            return focusable(this.$refs.panel, '[role="menuitem"]');
        },

        toggle() {
            this.open ? this.close(false) : this.openAndFocus('first');
        },

        openAndFocus(which) {
            this.open = true;
            this.$nextTick(() => {
                const items = this.items();
                (which === 'last' ? items[items.length - 1] : items[0])?.focus();
            });
        },

        close(returnFocus) {
            if (! this.open) {
                return;
            }
            this.open = false;
            if (returnFocus) {
                this.$refs.trigger?.focus();
            }
        },

        onKeydown(event) {
            const items = this.items();
            const index = items.indexOf(document.activeElement);
            const move = (to) => items[(to + items.length) % items.length]?.focus();

            switch (event.key) {
                case 'ArrowDown': event.preventDefault(); move(index + 1); break;
                case 'ArrowUp': event.preventDefault(); move(index - 1); break;
                case 'Home': event.preventDefault(); move(0); break;
                case 'End': event.preventDefault(); move(items.length - 1); break;
                case 'Tab': this.close(false); break;
                default:
                    if (event.key.length === 1 && /\S/.test(event.key)) {
                        const now = Date.now();
                        this.typed = now - this.typedAt > 600 ? event.key : this.typed + event.key;
                        this.typedAt = now;
                        items.find((item) => item.textContent.trim().toLowerCase().startsWith(this.typed.toLowerCase()))?.focus();
                    }
            }
        },
    }));

    Alpine.data('adminSelect', ({ value = null, open = false } = {}) => ({
        value,
        open,
        activeId: null,

        options() {
            return [...this.$refs.listbox.querySelectorAll('[role="option"]')];
        },

        toggle() {
            this.open ? this.close(false) : this.show();
        },

        show() {
            this.open = true;
            const selected = this.options().find((option) => option.dataset.value === String(this.value));
            this.activeId = (selected ?? this.options()[0])?.id ?? null;
        },

        close(returnFocus) {
            this.open = false;
            this.activeId = null;
            if (returnFocus) {
                this.$refs.trigger.focus();
            }
        },

        choose(option) {
            this.value = option.dataset.value;
            this.$refs.label.textContent = option.dataset.label;
            this.$refs.label.classList.remove('text-ink-3');
            this.options().forEach((each) => {
                const selected = each === option;
                each.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            const hidden = this.$root.querySelector('input[type="hidden"]');

            if (hidden) {
                hidden.value = this.value;
                hidden.dispatchEvent(new Event('input', { bubbles: true }));
            }

            this.close(true);
        },

        onKeydown(event) {
            const options = this.options();
            const index = options.findIndex((option) => option.id === this.activeId);
            const activate = (to) => {
                const option = options[Math.max(0, Math.min(options.length - 1, to))];
                this.activeId = option?.id ?? null;
                option?.scrollIntoView({ block: 'nearest' });
            };

            if (! this.open && ['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                this.show();
                return;
            }

            switch (event.key) {
                case 'ArrowDown': event.preventDefault(); activate(index + 1); break;
                case 'ArrowUp': event.preventDefault(); activate(index - 1); break;
                case 'Home': event.preventDefault(); activate(0); break;
                case 'End': event.preventDefault(); activate(options.length - 1); break;
                case 'Enter':
                case ' ':
                    event.preventDefault();
                    if (index >= 0) {
                        this.choose(options[index]);
                    }
                    break;
                case 'Tab': this.close(false); break;
            }
        },
    }));

    Alpine.data('adminDatePicker', ({ value = null, open = false, months = [], min = null, max = null } = {}) => {
        const pad = (n) => String(n).padStart(2, '0');
        const iso = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;
        const today = new Date();
        const [initialYear, initialMonth] = (value ?? iso(today.getFullYear(), today.getMonth() + 1, 1)).split('-').map(Number);

        return {
            value,
            open,
            year: initialYear,
            month: initialMonth,
            focused: value,

            get monthLabel() {
                return `${months[this.month - 1] ?? this.month} ${this.year}`;
            },

            get weeks() {
                const first = new Date(Date.UTC(this.year, this.month - 1, 1));
                const lead = (first.getUTCDay() + 6) % 7; // Monday first
                const start = new Date(Date.UTC(this.year, this.month - 1, 1 - lead));
                const weeks = [];

                for (let w = 0; w < 6; w++) {
                    const week = [];
                    for (let d = 0; d < 7; d++) {
                        const date = new Date(start);
                        date.setUTCDate(start.getUTCDate() + w * 7 + d);
                        const dayIso = iso(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
                        week.push({
                            iso: dayIso,
                            n: String(date.getUTCDate()),
                            outside: date.getUTCMonth() !== this.month - 1,
                            disabled: (min !== null && dayIso < min) || (max !== null && dayIso > max),
                        });
                    }
                    weeks.push(week);
                }

                return weeks;
            },

            toggle() {
                this.open ? this.close(false) : this.show();
            },

            show() {
                this.open = true;
                this.focused ??= iso(this.year, this.month, 1);
                this.$nextTick(() => this.$refs.dialog.querySelector(`[data-iso="${this.focused}"]`)?.focus());
            },

            close(returnFocus) {
                this.open = false;
                if (returnFocus) {
                    this.$refs.trigger.focus();
                }
            },

            shiftMonth(by) {
                const date = new Date(Date.UTC(this.year, this.month - 1 + by, 1));
                this.year = date.getUTCFullYear();
                this.month = date.getUTCMonth() + 1;
            },

            pick(day) {
                if (day.disabled) {
                    return;
                }
                this.value = day.iso;
                this.focused = day.iso;
                this.$el.closest('[x-data]').querySelector('input[type="hidden"]')?.dispatchEvent(new Event('input', { bubbles: true }));
                this.close(true);
            },

            dayClass(day) {
                if (day.iso === this.value) {
                    return 'bg-accent text-on-accent font-extrabold';
                }
                if (day.disabled) {
                    return 'text-ink-3 cursor-not-allowed';
                }
                return day.outside ? 'text-ink-3 hover:bg-sunken' : 'text-ink hover:bg-sunken';
            },

            onGridKeydown(event) {
                const rtl = document.documentElement.dir === 'rtl';
                const steps = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, ArrowDown: 7, ArrowUp: -7 };
                const [y, m, d] = (this.focused ?? this.value).split('-').map(Number);
                let next = null;

                if (event.key in steps) {
                    next = new Date(Date.UTC(y, m - 1, d + steps[event.key]));
                } else if (event.key === 'PageDown' || event.key === 'PageUp') {
                    next = new Date(Date.UTC(y, m - 1 + (event.key === 'PageDown' ? 1 : -1), d));
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    this.pick(this.weeks.flat().find((day) => day.iso === this.focused));
                    return;
                } else {
                    return;
                }

                event.preventDefault();
                this.focused = iso(next.getUTCFullYear(), next.getUTCMonth() + 1, next.getUTCDate());
                this.year = next.getUTCFullYear();
                this.month = next.getUTCMonth() + 1;
                this.$nextTick(() => this.$refs.dialog.querySelector(`[data-iso="${this.focused}"]`)?.focus());
            },
        };
    });

    Alpine.data('adminMoneyInput', ({ minor = '', exponent = 2 } = {}) => {
        const toDisplay = (value) => {
            if (value === '' || value === null) {
                return '';
            }
            const negative = value.startsWith('-');
            const digits = value.replace('-', '').padStart(exponent + 1, '0');
            const major = digits.slice(0, digits.length - exponent);
            const fraction = exponent > 0 ? `.${digits.slice(-exponent)}` : '';
            return `${negative ? '-' : ''}${major}${fraction}`;
        };

        return {
            minor,
            display: toDisplay(minor),

            sync() {
                const text = this.display.replace(/[,\s]/g, '');
                const match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(text);

                if (! match || (match[2] === '' && (match[3] ?? '') === '')) {
                    this.minor = '';
                    return;
                }

                const fraction = (match[3] ?? '').slice(0, exponent).padEnd(exponent, '0');
                const digits = `${match[2]}${fraction}`.replace(/^0+(?=\d)/, '');
                this.minor = `${match[1] === '-' && /[1-9]/.test(digits) ? '-' : ''}${digits || '0'}`;
            },
        };
    });

    Alpine.data('adminDialog', (name, dismissible = true, extra = {}) => ({
        ...extra,
        name,
        trapped: false,
        opener: null,

        show() {
            if (this.$root.open) {
                return;
            }
            this.opener = document.activeElement;
            this.$root.showModal();
            this.trapped = true;
        },

        hide() {
            this.trapped = false;
            if (this.$root.open) {
                this.$root.close();
            }
        },

        onCancel(event) {
            if (! dismissible) {
                event.preventDefault();
                return;
            }
            this.trapped = false;
        },

        restoreFocus() {
            this.trapped = false;
            this.opener?.focus?.();
            this.opener = null;
        },
    }));

    Alpine.data('adminCopy', (value) => ({
        copied: false,

        async copy() {
            try {
                await navigator.clipboard.writeText(value);
            } catch {
                const field = Object.assign(document.createElement('textarea'), { value });
                document.body.append(field);
                field.select();
                document.execCommand('copy');
                field.remove();
            }
            this.copied = true;
            setTimeout(() => (this.copied = false), 2000);
        },
    }));

    Alpine.data('adminToasts', (initial = [], styles = {}) => ({
        items: [],
        styles,
        sequence: 0,

        init() {
            initial.forEach((toast) => this.push(toast));
        },

        push(detail) {
            const toast = { id: ++this.sequence, tone: detail.tone ?? 'info', message: detail.message ?? '', undo: detail.undo ?? null, timer: null, remaining: 6000, started: 0 };
            this.items = [...this.items, toast].slice(-3);
            this.resume(toast);
        },

        dismiss(toast) {
            clearTimeout(toast.timer);
            this.items = this.items.filter((item) => item.id !== toast.id);
        },

        pause(toast) {
            if (toast.timer) {
                clearTimeout(toast.timer);
                toast.timer = null;
                toast.remaining -= Date.now() - toast.started;
            }
        },

        resume(toast) {
            if (toast.tone === 'error' || toast.timer) {
                return;
            }
            toast.started = Date.now();
            toast.timer = setTimeout(() => this.dismiss(toast), Math.max(1000, toast.remaining));
        },

        undo(toast) {
            window.Livewire?.dispatch(toast.undo);
            this.dismiss(toast);
        },
    }));

    // Six boxes that enhance one ordinary field: without JavaScript the field is
    // all there is, and it submits as `one_time_code` either way.
    Alpine.data('adminCodeInput', (length = 6) => ({
        enhanced: false,
        digits: Array(length).fill(''),

        init() {
            this.enhanced = true;
            this.$nextTick(() => this.boxes()[0]?.focus());
        },

        boxes() {
            return [...this.$refs.boxes.querySelectorAll('[data-box]')];
        },

        sync() {
            this.$refs.field.value = this.digits.join('');
        },

        onInput(index, event) {
            const typed = event.target.value.replace(/\D/g, '');

            if (typed.length > 1) {
                this.fill(typed, index);
                return;
            }

            this.digits[index] = typed;
            event.target.value = typed;
            this.sync();

            if (typed !== '') {
                this.boxes()[index + 1]?.focus();
            }
        },

        onKeydown(index, event) {
            const boxes = this.boxes();
            // Start and end are logical: the boxes read left to right in both languages, like the digits.
            const moves = { ArrowLeft: -1, ArrowRight: 1 };

            if (event.key === 'Backspace' && this.digits[index] === '' && index > 0) {
                boxes[index - 1].focus();
                this.digits[index - 1] = '';
                boxes[index - 1].value = '';
                this.sync();
                event.preventDefault();
            } else if (event.key in moves) {
                boxes[index + moves[event.key]]?.focus();
                event.preventDefault();
            }
        },

        onPaste(index, event) {
            const pasted = (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '');

            if (pasted !== '') {
                event.preventDefault();
                this.fill(pasted, 0);
            }
        },

        fill(text, from) {
            const boxes = this.boxes();

            [...text.slice(0, length - from)].forEach((digit, offset) => {
                this.digits[from + offset] = digit;
                boxes[from + offset].value = digit;
            });
            this.sync();
            boxes[Math.min(from + text.length, length - 1)].focus();
        },
    }));

    Alpine.data('adminCountdown', (serverTime, step = 30) => ({
        offset: serverTime * 1000 - Date.now(),
        seconds: step,

        init() {
            this.tick();
            setInterval(() => this.tick(), 1000);
        },

        tick() {
            const now = Math.floor((Date.now() + this.offset) / 1000);
            this.seconds = step - (now % step);
        },
    }));

    Alpine.data('adminDownload', (text, filename) => ({
        save() {
            const link = Object.assign(document.createElement('a'), {
                href: URL.createObjectURL(new Blob([text], { type: 'text/plain' })),
                download: filename,
            });
            document.body.append(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(link.href);
        },
    }));
});

// A component update refused with 401 (the session ended) sends the browser to
// step 1 instead of showing an error (spec 018, R-8).
document.addEventListener('livewire:init', () => {
    // A dialog opened with showModal() has no `open` attribute in the server's
    // HTML, so a morph would close it (hiding a validation error it just
    // received). Keep an open dialog open across updates.
    window.Livewire.hook('morph.updating', ({ el, toEl }) => {
        if (el instanceof HTMLDialogElement && el.open) {
            toEl.setAttribute('open', '');
        }
    });

    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 401) {
                preventDefault();
                window.location.assign('/admin/sign-in');
            }
        });
    });
});
