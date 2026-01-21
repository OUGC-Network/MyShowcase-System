document.addEventListener('DOMContentLoaded', function () {
    const entries_total_count = totalEntries;

    const master_checkbox = document.getElementById('select-all-entries');

    const page_checkboxes = document.querySelectorAll('.thread-list input[type="checkbox"][id^="selected-entry-"]');

    const counter_element = document.getElementById('selected-counter');

    const selected_all_input = document.getElementById('selected-all-entries');

    const selected_input = document.getElementById('selected-entries');

    const unselected_input = document.getElementById('unselected-entries');

    let save_all_entries = localStorage.getItem('selected_entries_all_' + showcaseID) || 0;

    console.log(save_all_entries);

    let saved_selections = JSON.parse(localStorage.getItem('selected_entries_' + showcaseID) || '[]') || [];

    let unsaved_selections = JSON.parse(localStorage.getItem('unselected_entries_' + showcaseID) || '[]') || [];

    let selected_count = saved_selections.length;

    /** @type {HTMLElement|null} */
    let page_selected_row = document.getElementById('page_select_row');

    /** @type {HTMLElement|null} */
    let all_selected_row = document.getElementById('all_select_row');

    let selected_page_count = 0;

    master_checkbox.checked = true;

    page_checkboxes.forEach(checkbox => {
        const closest_parent = checkbox.closest('.inline_row');

        if ((save_all_entries && !unsaved_selections.includes(checkbox.dataset.slug)) ||
            saved_selections.includes(checkbox.dataset.slug)) {
            checkbox.checked = true;

            ++selected_page_count;

            if (closest_parent) {
                closest_parent.classList.add('trow_selected');
            }
        } else {
            master_checkbox.checked = false;

            if (closest_parent) {
                closest_parent.classList.remove('trow_selected');
            }
        }
    });

    if ((save_all_entries && !unsaved_selections) || selected_count >= entries_total_count) {
        all_selected_row.style.display = 'block';

        all_selected_row.querySelector('strong').textContent = entries_total_count.toString();
    } else {
        all_selected_row.style.display = 'none';

        if (selected_page_count >= entriesPerPage) {
            page_selected_row.style.display = 'block';

            page_selected_row.querySelector('strong').textContent = entriesPerPage.toString();
        } else {
            page_selected_row.style.display = 'none';
        }
    }

    if (save_all_entries) {
        selected_count = entries_total_count - unsaved_selections.length;
    }

    counter_element.textContent = selected_count.toString();

    selected_all_input.value = JSON.stringify(save_all_entries);

    selected_input.value = JSON.stringify(saved_selections);

    unselected_input.value = JSON.stringify(unsaved_selections);

    master_checkbox.addEventListener('change', function () {
        const is_checked = this.checked;

        page_checkboxes.forEach(checkbox => {
            const closest_parent = checkbox.closest('.inline_row');

            if (checkbox.checked && !is_checked) {
                checkbox.checked = false;

                saved_selections = saved_selections.filter(item => item !== checkbox.dataset.slug);

                unsaved_selections.push(checkbox.dataset.slug);

                if (closest_parent) {
                    closest_parent.classList.remove('trow_selected');
                }
            } else if (!checkbox.checked && is_checked) {
                checkbox.checked = true;

                saved_selections.push(checkbox.dataset.slug);

                unsaved_selections = unsaved_selections.filter(item => item !== checkbox.dataset.slug);

                if (closest_parent) {
                    closest_parent.classList.add('trow_selected');
                }
            }
        });

        localStorage.setItem('selected_entries_' + showcaseID, JSON.stringify(saved_selections));

        localStorage.setItem('unselected_entries_' + showcaseID, JSON.stringify(unsaved_selections));

        if (save_all_entries) {
            selected_count = entries_total_count - unsaved_selections.length;
        } else {
            selected_count = saved_selections.length;
        }

        counter_element.textContent = selected_count.toString();

        selected_input.value = JSON.stringify(saved_selections);

        unselected_input.value = JSON.stringify(unsaved_selections);

        if (selected_count >= entries_total_count) {
            selected_all_input.value = '1';

            selected_input.value = '';

            localStorage.setItem('selected_entries_all_' + showcaseID, '1');

            localStorage.setItem('unselected_entries_' + showcaseID, '');

            all_selected_row.style.display = 'block';

            all_selected_row.querySelector('strong').textContent = selected_count.toString();
        } else {
            if (save_all_entries) {
                selected_all_input.value = '1';

                selected_input.value = '';
            } else {
                selected_all_input.value = '';
            }

            //localStorage.setItem('selected_entries_all_' + showcaseID, '');

            all_selected_row.style.display = 'none';

            if (is_checked) {
                page_selected_row.style.display = 'block';

                page_selected_row.querySelector('strong').textContent = entriesPerPage.toString();
            } else {
                page_selected_row.style.display = 'none';
            }
        }
    });

    page_checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const is_checked = this.checked;

            const closest_parent = checkbox.closest('.inline_row');

            if (is_checked) {
                if (!saved_selections.includes(this.dataset.slug)) {
                    saved_selections.push(this.dataset.slug);
                }

                unsaved_selections = unsaved_selections.filter(id => id !== this.dataset.slug);

                if (closest_parent) {
                    closest_parent.classList.add('trow_selected');
                }
            } else if (!is_checked) {
                saved_selections = saved_selections.filter(id => id !== this.dataset.slug);

                unsaved_selections.push(this.dataset.slug);

                if (closest_parent) {
                    closest_parent.classList.remove('trow_selected');
                }
            }

            localStorage.setItem('selected_entries_' + showcaseID, JSON.stringify(saved_selections));

            localStorage.setItem('unselected_entries_' + showcaseID, JSON.stringify(unsaved_selections));

            if (save_all_entries) {
                selected_count = entries_total_count - unsaved_selections.length;
            } else {
                selected_count = saved_selections.length;
            }

            console.log(selected_count, unsaved_selections);

            counter_element.textContent = selected_count.toString();

            selected_input.value = JSON.stringify(saved_selections);

            unselected_input.value = JSON.stringify(unsaved_selections);

            master_checkbox.checked = false;

            let selected_page_count = 0;

            page_checkboxes.forEach(checkbox => {
                if (saved_selections.includes(checkbox.dataset.slug)) {
                    ++selected_page_count;
                }
            });

            if (selected_page_count >= entriesPerPage) {
                master_checkbox.checked = true;
            }

            if (save_all_entries) {
                //let selected_count = entries_total_count - unsaved_selections.length;
            }

            if (selected_count >= entries_total_count) {
                selected_all_input.value = '1';

                selected_input.value = '';

                localStorage.setItem('selected_entries_all_' + showcaseID, '1');

                localStorage.setItem('unselected_entries_' + showcaseID, '');

                all_selected_row.style.display = 'block';

                all_selected_row.querySelector('strong').textContent = selected_count.toString();
            } else {
                if (save_all_entries) {
                    selected_all_input.value = '1';

                    selected_input.value = '';
                } else {
                    selected_all_input.value = '';
                }

                //localStorage.setItem('selected_entries_all_' + showcaseID, '');

                all_selected_row.style.display = 'none';

                if (selected_page_count >= entriesPerPage) {
                    page_selected_row.style.display = 'block';

                    page_selected_row.querySelector('strong').textContent = entriesPerPage.toString();
                } else {
                    page_selected_row.style.display = 'none';
                }
            }
        });
    });

    const select_all_button = document.querySelectorAll('.select_all_entries');

    select_all_button.forEach(button => {
        if (selected_count >= entries_total_count) {
            button.style.display = 'none';

            page_selected_row.style.display = 'none';
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();

            select_all_button.forEach(button => {
                button.style.display = 'none';

                page_selected_row.style.display = 'none';
            });

            master_checkbox.checked = true;

            page_checkboxes.forEach(checkbox => {
                checkbox.checked = true;

                const closest_parent = checkbox.closest('.inline_row');

                if (closest_parent) {
                    closest_parent.classList.add('trow_selected');
                }
            });

            selected_all_input.value = '1';

            selected_input.value = '';

            localStorage.setItem('selected_entries_all_' + showcaseID, '1');

            localStorage.setItem('unselected_entries_' + showcaseID, '');

            counter_element.textContent = entries_total_count.toString();

            page_selected_row.style.display = 'hide';

            all_selected_row.style.display = 'block';

            all_selected_row.querySelector('strong').textContent = entries_total_count.toString();

            console.log(entriesPerPage, entries_total_count);
        });
    });

    const clear_all_button = document.querySelectorAll('.selected_entries_clear');

    clear_all_button.forEach(button => {
        button.addEventListener('click', function (event) {
            event.preventDefault();

            master_checkbox.checked = false;

            page_checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });

            selected_all_input.value = '0';

            localStorage.setItem('selected_entries_all_' + showcaseID, '');

            localStorage.setItem('selected_entries_' + showcaseID, '');

            localStorage.setItem('unselected_entries_' + showcaseID, '');

            counter_element.textContent = '0';

            all_selected_row.style.display = 'none';

            page_selected_row.style.display = 'none';

            document.querySelectorAll('.inline_row').forEach(checkbox => {
                const closest_parent = checkbox.closest('.inline_row');

                if (closest_parent) {
                    closest_parent.classList.remove('trow_selected');
                }
            });
        });
    });

    const form = document.getElementById('inline_moderation_form');

    form.addEventListener('submit', function (event) {
        const moderation_action = form.querySelector('select[name="moderate"]');

        const selected_all_entries = form.querySelector('input[name="selected_all_entries"]');

        const selected_entries = form.querySelector('input[name="selected_entries"]');

        if (!moderation_action.value.trim()) {
            event.preventDefault();

            $.jGrowl(lang.inline_moderation_no_tool_selected, {theme: 'jgrowl_error'});
        } else if (!selected_all_entries.value.trim() && !selected_entries.value.trim()) {
            event.preventDefault();

            $.jGrowl(lang.inline_moderation_no_entries_selected, {theme: 'jgrowl_error'});
        }
    });
});