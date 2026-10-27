/*
 * The menu's item tree, dragged (PLAN §39.7). Native drag and drop rather than
 * a library, as the product form's characteristics already do: the whole
 * behaviour is pick a row up, hover another, drop before / inside / after it.
 *
 * The page is a nested list of <li data-menu-item>, each holding its row and
 * an <ol data-menu-children> (always there, empty or not). A drop moves the
 * <li> in the DOM, then posts where EVERY row now stands — parent and position
 * — to the arrange endpoint, which checks the tree as a whole. Anything but a
 * 2xx reloads the page: what is stored is the truth, and a tree drawn one way
 * and saved another is the one outcome worse than a lost drag.
 *
 * A row is draggable only while the pointer is down on its handle, so its
 * link, its buttons and selecting its text keep working. With no script the
 * handles stay hidden and the arrows on each row do the same job.
 */
(() => {
    const tree = document.querySelector('[data-menu-tree]');

    if (!tree) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let dragged = null;
    let marked = null;

    tree.querySelectorAll('[data-menu-handle]').forEach((handle) => handle.classList.remove('hidden'));

    const rowOf = (li) => li.querySelector(':scope > [data-menu-row]');

    const unmark = () => {
        marked?.removeAttribute('data-drop');
        marked = null;
    };

    /* Upper quarter = before, lower quarter = after, the middle = inside. */
    const placeFor = (row, event) => {
        const box = row.getBoundingClientRect();
        const y = (event.clientY - box.top) / box.height;

        return y < 0.25 ? 'before' : (y > 0.75 ? 'after' : 'inside');
    };

    tree.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-menu-handle]');

        if (handle) {
            handle.closest('[data-menu-row]').draggable = true;
        }
    });

    // Pressed and let go without a drag: the row goes back to being text and links.
    tree.addEventListener('pointerup', () => {
        if (!dragged) {
            tree.querySelectorAll('[data-menu-row][draggable="true"]').forEach((row) => { row.draggable = false; });
        }
    });

    tree.addEventListener('dragstart', (event) => {
        const row = event.target.closest?.('[data-menu-row]');

        if (!row || !row.draggable) {
            return;
        }

        dragged = row.closest('[data-menu-item]');
        row.setAttribute('data-dragging', '');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', dragged.dataset.menuItem);
    });

    tree.addEventListener('dragover', (event) => {
        const row = event.target.closest?.('[data-menu-row]');

        if (!dragged || !row) {
            return;
        }

        const li = row.closest('[data-menu-item]');

        // Not onto itself, and not into its own branch — that would cut the branch off.
        if (dragged.contains(li)) {
            unmark();

            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        if (marked !== row) {
            unmark();
            marked = row;
        }

        row.setAttribute('data-drop', placeFor(row, event));
    });

    tree.addEventListener('dragleave', (event) => {
        if (marked && !marked.contains(event.relatedTarget)) {
            unmark();
        }
    });

    tree.addEventListener('drop', (event) => {
        if (!dragged || !marked) {
            return;
        }

        event.preventDefault();

        const target = marked.closest('[data-menu-item]');
        const place = marked.getAttribute('data-drop');

        unmark();

        if (place === 'before') {
            target.before(dragged);
        } else if (place === 'after') {
            target.after(dragged);
        } else {
            target.querySelector(':scope > [data-menu-children]').append(dragged);
        }

        save();
    });

    tree.addEventListener('dragend', () => {
        unmark();

        if (dragged) {
            const row = rowOf(dragged);
            row.removeAttribute('data-dragging');
            row.draggable = false;
        }

        dragged = null;
    });

    /* Every row's parent and position, as the DOM now has them. */
    const save = () => {
        const body = new FormData();

        tree.querySelectorAll('[data-menu-item]').forEach((li) => {
            const parent = li.parentElement.closest('[data-menu-item]');
            const position = [...li.parentElement.children].indexOf(li) + 1;

            body.append(`items[${li.dataset.menuItem}][parent]`, parent ? parent.dataset.menuItem : '0');
            body.append(`items[${li.dataset.menuItem}][sort]`, String(position * 10));
        });

        fetch(tree.dataset.arrangeUrl, {
            method: 'POST',
            body,
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            credentials: 'same-origin',
        }).then((response) => {
            if (!response.ok) {
                throw new Error(String(response.status));
            }
        }).catch(() => {
            window.alert(tree.dataset.error);
            window.location.reload();
        });
    };
})();
