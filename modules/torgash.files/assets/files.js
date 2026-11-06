/*
 * The file manager screen (torgash.files): Alpine's `torgashFiles`.
 *
 * Uploading: files picked or dropped anywhere on the card go up ONE PER
 * REQUEST, three at a time — one refused file does not sink the rest, and
 * no request is bigger than one file. Built for a batch of thousands: the
 * waiting files are a plain array outside Alpine (no reactive copy of 2000
 * entries), and the screen draws ONE overall bar, a line per file being sent
 * right now, and the refusals. When the last one is done the page is drawn
 * again (the list is the server's, never rebuilt here).
 *
 * Selection: ticked files can have their links copied in one go, joined by
 * ", " — the shape a "photos for a marketplace" field and its feed expect —
 * or be moved or deleted by the bulk form.
 *
 * Preview: one shared <dialog>, filled from the data-* attributes of the tile
 * or list row that opened it.
 *
 * Loaded as a plain script before Alpine (deferred) starts, which is what
 * lets it register on `alpine:init`.
 */
(() => {
    if (window.torgashFilesScript) {
        return;
    }
    window.torgashFilesScript = true;

    const PARALLEL = 3;
    const SHOWN_ERRORS = 50;

    document.addEventListener('alpine:init', () => {
        window.Alpine.data('torgashFiles', () => {
            // Outside the reactive object on purpose: thousands of File handles
            // Alpine has no reason to watch.
            const queue = [];

            return {
                selected: [],
                dragging: 0,
                copied: false,
                copiedTimer: null,
                file: {},

                total: 0,
                finished: 0,
                errorCount: 0,
                errors: [],
                sending: [],

                get busy() {
                    return this.finished < this.total;
                },

                get percent() {
                    if (!this.total) {
                        return 0;
                    }
                    const partial = this.sending.reduce((sum, u) => sum + u.progress / 100, 0);
                    return Math.round(((this.finished + partial) / this.total) * 100);
                },

                pick(event) {
                    this.send([...event.target.files]);
                    event.target.value = '';
                },

                drop(event) {
                    this.dragging = 0;
                    const files = [...(event.dataTransfer?.files || [])];
                    if (files.length) {
                        this.send(files);
                    }
                },

                send(files) {
                    queue.push(...files);
                    this.total += files.length;
                    while (this.sending.length < PARALLEL && queue.length) {
                        this.next();
                    }
                },

                next() {
                    const picked = queue.shift();
                    if (!picked) {
                        if (!this.busy && this.total && !this.errorCount) {
                            window.location.reload();
                        }
                        return;
                    }

                    const upload = { id: Math.random().toString(36).slice(2), name: picked.name, progress: 0 };
                    this.sending.push(upload);
                    const live = () => this.sending.find((u) => u.id === upload.id);

                    const finish = (error) => {
                        this.sending = this.sending.filter((u) => u.id !== upload.id);
                        this.finished++;
                        if (error) {
                            this.errorCount++;
                            if (this.errors.length < SHOWN_ERRORS) {
                                this.errors.push(error);
                            }
                        }
                        this.next();
                    };

                    const body = new FormData();
                    body.append('files[]', picked);
                    body.append('folder', this.$root.dataset.folder || '');

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', this.$root.dataset.uploadUrl);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
                    xhr.upload.onprogress = (e) => {
                        const row = live();
                        if (row && e.lengthComputable) {
                            row.progress = Math.round((e.loaded / e.total) * 100);
                        }
                    };
                    xhr.onload = () => {
                        let answer = {};
                        try {
                            answer = JSON.parse(xhr.responseText);
                        } catch (e) {
                            // A page instead of an answer: the server's own limit, most likely.
                        }
                        const refused = (answer.errors || [])[0] || (xhr.status === 200 ? '' : answer.message || `${picked.name}: ${this.$root.dataset.failed}`);
                        finish(refused);
                    };
                    xhr.onerror = () => finish(`${picked.name}: ${this.$root.dataset.failed}`);
                    xhr.send(body);
                },

                // `key` says WHICH button was pressed, so that one — and only
                // that one — turns into a tick for a moment.
                async copyLinks(urls, key = 'selected') {
                    const text = urls.join(', ');
                    try {
                        await navigator.clipboard.writeText(text);
                    } catch (e) {
                        // No clipboard (an address without https): put it where it can be copied by hand.
                        window.prompt('', text);
                        return;
                    }
                    this.copied = key;
                    clearTimeout(this.copiedTimer);
                    this.copiedTimer = setTimeout(() => (this.copied = false), 1500);
                },

                copySelected() {
                    const urls = this.selected
                        .map((id) => this.$root.querySelector(`[data-file-id="${id}"]`)?.dataset.url)
                        .filter(Boolean);
                    this.copyLinks(urls, 'selected');
                },

                selectPage() {
                    this.selected = [...this.$root.querySelectorAll('[data-file-id]')].map((el) => Number(el.dataset.fileId));
                },

                open(opener) {
                    this.file = { ...opener.dataset };
                    this.$refs.preview.showModal();
                },
            };
        });
    });
})();
