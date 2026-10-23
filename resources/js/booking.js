/**
 * Public booking page: month calendar, time list and time zone selection.
 *
 * The server returns slots for a month grouped by date in the chosen time
 * zone, as ISO 8601 strings with offsets. Dates here are plain "YYYY-MM-DD"
 * strings so browser time zones never shift them.
 */
export default (config) => ({
    config,
    timezone: null,
    month: null, // "YYYY-MM"
    days: {},
    selectedDate: null,
    selected: null, // ISO start time
    loading: false,
    failed: false,
    request: 0,

    init() {
        const known = (tz) => tz && config.timezones.includes(tz);
        const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;

        this.timezone = known(config.old.timezone) ? config.old.timezone
            : known(browserTz) ? browserTz
            : config.defaultTimezone;

        // After a validation error, reopen the form on the time that was picked.
        if (config.old.start) {
            this.selected = config.old.start;
            this.selectedDate = this.dateIn(config.old.start);
        }

        this.month = (this.selectedDate || this.today()).slice(0, 7);
        this.load();
    },

    async load() {
        const request = ++this.request;
        this.loading = true;
        this.failed = false;

        try {
            const url = `${config.slotsUrl}?${new URLSearchParams({ month: this.month, timezone: this.timezone })}`;
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(response.status);
            const data = await response.json();

            if (request !== this.request) return; // a newer request superseded this one
            this.days = data.days;

            if (!this.days[this.selectedDate]) {
                this.selectedDate = Object.keys(this.days).sort()[0] || null;
            }
        } catch {
            if (request === this.request) {
                this.days = {};
                this.failed = true;
            }
        } finally {
            if (request === this.request) this.loading = false;
        }
    },

    changeMonth(delta) {
        const [y, m] = this.month.split('-').map(Number);
        const d = new Date(Date.UTC(y, m - 1 + delta, 1));
        this.month = d.toISOString().slice(0, 7);
        this.selectedDate = null;
        this.load();
    },

    canGoBack() {
        return this.month > this.today().slice(0, 7);
    },

    canGoForward() {
        return this.month < config.lastDate.slice(0, 7);
    },

    selectDate(date) {
        if (this.days[date]) this.selectedDate = date;
    },

    /** Calendar cells for the month, Monday first, with blanks for padding. */
    calendarCells() {
        const [y, m] = this.month.split('-').map(Number);
        const first = new Date(Date.UTC(y, m - 1, 1));
        const daysInMonth = new Date(Date.UTC(y, m, 0)).getUTCDate();
        const padding = (first.getUTCDay() + 6) % 7;

        const cells = Array.from({ length: padding }, (_, i) => ({ key: `pad-${i}`, date: null, day: '' }));
        for (let day = 1; day <= daysInMonth; day++) {
            const date = `${this.month}-${String(day).padStart(2, '0')}`;
            cells.push({ key: date, date, day });
        }
        return cells;
    },

    weekdayNames() {
        // 2024-01-01 was a Monday.
        return Array.from({ length: 7 }, (_, i) =>
            new Intl.DateTimeFormat(undefined, { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1 + i))));
    },

    monthLabel() {
        const [y, m] = this.month.split('-').map(Number);
        return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, 1)));
    },

    formatDay(date) {
        const [y, m, d] = date.split('-').map(Number);
        return new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)));
    },

    formatTime(iso) {
        return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', timeZone: this.timezone }).format(new Date(iso));
    },

    formatLong(iso) {
        return new Intl.DateTimeFormat(undefined, {
            weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: this.timezone, timeZoneName: 'short',
        }).format(new Date(iso));
    },

    /** "YYYY-MM-DD" of an instant in the selected time zone. */
    dateIn(iso) {
        return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: this.timezone }).format(new Date(iso));
    },

    today() {
        return this.dateIn(new Date().toISOString());
    },
});
