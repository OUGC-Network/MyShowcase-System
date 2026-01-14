document.addEventListener('DOMContentLoaded', function () {
    const entriesTotalCount = totalEntries;

    const masterCheckbox = document.getElementById('select-all-entries');

    const pageCheckboxes = document.querySelectorAll('.thread-list input[type="checkbox"][id^="selected-entry-"]');

    const counterElement = document.getElementById('selected-counter');

    const selectedAllInput = document.getElementById('selected-all-entries');

    const selectedInput = document.getElementById('selected-entries');

    const unselectedInput = document.getElementById('unselected-entries');

    let savedAllEntries = localStorage.getItem('selected_entries_all_' + showcaseID) || 0;

    console.log(savedAllEntries);

    let savedSelections = JSON.parse(localStorage.getItem('selected_entries_' + showcaseID) || '[]') || [];

    let unsavedSelections = JSON.parse(localStorage.getItem('unselected_entries_' + showcaseID) || '[]') || [];

    let selectedCount = savedSelections.length;

    /** @type {HTMLElement|null} */
    let pageSelectedRow = document.getElementById('page_select_row');

    /** @type {HTMLElement|null} */
    let allSelectedRow = document.getElementById('all_select_row');

    let selectedPageCount = 0;

    masterCheckbox.checked = true;

    pageCheckboxes.forEach(checkbox => {
        const closestParent = checkbox.closest('.inline_row');

        if ((savedAllEntries && !unsavedSelections.includes(checkbox.dataset.slug)) ||
            savedSelections.includes(checkbox.dataset.slug)) {
            checkbox.checked = true;

            ++selectedPageCount;

            if (closestParent) {
                closestParent.classList.add('trow_selected');
            }
        } else {
            masterCheckbox.checked = false;

            if (closestParent) {
                closestParent.classList.remove('trow_selected');
            }
        }
    });

    if ((savedAllEntries && !unsavedSelections) || selectedCount >= entriesTotalCount) {
        allSelectedRow.style.display = 'block';

        allSelectedRow.querySelector('strong').textContent = entriesTotalCount.toString();
    } else {
        allSelectedRow.style.display = 'none';

        if (selectedPageCount >= entriesPerPage) {
            pageSelectedRow.style.display = 'block';

            pageSelectedRow.querySelector('strong').textContent = entriesPerPage.toString();
        } else {
            pageSelectedRow.style.display = 'none';
        }
    }

    if (savedAllEntries) {
        selectedCount = entriesTotalCount - unsavedSelections.length;
    }

    counterElement.textContent = selectedCount.toString();

    selectedAllInput.value = JSON.stringify(savedAllEntries);

    selectedInput.value = JSON.stringify(savedSelections);

    unselectedInput.value = JSON.stringify(unsavedSelections);

    masterCheckbox.addEventListener('change', function () {
        const isChecked = this.checked;

        pageCheckboxes.forEach(checkbox => {
            const closestParent = checkbox.closest('.inline_row');

            if (checkbox.checked && !isChecked) {
                checkbox.checked = false;

                savedSelections = savedSelections.filter(item => item !== checkbox.dataset.slug);

                unsavedSelections.push(checkbox.dataset.slug);

                if (closestParent) {
                    closestParent.classList.remove('trow_selected');
                }
            } else if (!checkbox.checked && isChecked) {
                checkbox.checked = true;

                savedSelections.push(checkbox.dataset.slug);

                unsavedSelections = unsavedSelections.filter(item => item !== checkbox.dataset.slug);

                if (closestParent) {
                    closestParent.classList.add('trow_selected');
                }
            }
        });

        localStorage.setItem('selected_entries_' + showcaseID, JSON.stringify(savedSelections));

        localStorage.setItem('unselected_entries_' + showcaseID, JSON.stringify(unsavedSelections));

        if (savedAllEntries) {
            selectedCount = entriesTotalCount - unsavedSelections.length;
        } else {
            selectedCount = savedSelections.length;
        }

        counterElement.textContent = selectedCount.toString();

        selectedInput.value = JSON.stringify(savedSelections);

        unselectedInput.value = JSON.stringify(unsavedSelections);

        if (selectedCount >= entriesTotalCount) {
            selectedAllInput.value = '1';

            selectedInput.value = '';

            localStorage.setItem('selected_entries_all_' + showcaseID, '1');

            localStorage.setItem('unselected_entries_' + showcaseID, '');

            allSelectedRow.style.display = 'block';

            allSelectedRow.querySelector('strong').textContent = selectedCount.toString();
        } else {
            if (savedAllEntries) {
                selectedAllInput.value = '1';

                selectedInput.value = '';
            } else {
                selectedAllInput.value = '';
            }

            //localStorage.setItem('selected_entries_all_' + showcaseID, '');

            allSelectedRow.style.display = 'none';

            if (isChecked) {
                pageSelectedRow.style.display = 'block';

                pageSelectedRow.querySelector('strong').textContent = entriesPerPage.toString();
            } else {
                pageSelectedRow.style.display = 'none';
            }
        }
    });

    pageCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const isChecked = this.checked;

            const closestParent = checkbox.closest('.inline_row');

            if (isChecked) {
                if (!savedSelections.includes(this.dataset.slug)) {
                    savedSelections.push(this.dataset.slug);
                }

                unsavedSelections = unsavedSelections.filter(id => id !== this.dataset.slug);

                if (closestParent) {
                    closestParent.classList.add('trow_selected');
                }
            } else if (!isChecked) {
                savedSelections = savedSelections.filter(id => id !== this.dataset.slug);

                unsavedSelections.push(this.dataset.slug);

                if (closestParent) {
                    closestParent.classList.remove('trow_selected');
                }
            }

            localStorage.setItem('selected_entries_' + showcaseID, JSON.stringify(savedSelections));

            localStorage.setItem('unselected_entries_' + showcaseID, JSON.stringify(unsavedSelections));

            if (savedAllEntries) {
                selectedCount = entriesTotalCount - unsavedSelections.length;
            } else {
                selectedCount = savedSelections.length;
            }

            console.log(selectedCount, unsavedSelections);

            counterElement.textContent = selectedCount.toString();

            selectedInput.value = JSON.stringify(savedSelections);

            unselectedInput.value = JSON.stringify(unsavedSelections);

            masterCheckbox.checked = false;

            let selectedPageCount = 0;

            pageCheckboxes.forEach(checkbox => {
                if (savedSelections.includes(checkbox.dataset.slug)) {
                    ++selectedPageCount;
                }
            });

            if (selectedPageCount >= entriesPerPage) {
                masterCheckbox.checked = true;
            }

            if (savedAllEntries) {
                //let selectedCount = entriesTotalCount - unsavedSelections.length;
            }

            if (selectedCount >= entriesTotalCount) {
                selectedAllInput.value = '1';

                selectedInput.value = '';

                localStorage.setItem('selected_entries_all_' + showcaseID, '1');

                localStorage.setItem('unselected_entries_' + showcaseID, '');

                allSelectedRow.style.display = 'block';

                allSelectedRow.querySelector('strong').textContent = selectedCount.toString();
            } else {
                if (savedAllEntries) {
                    selectedAllInput.value = '1';

                    selectedInput.value = '';
                } else {
                    selectedAllInput.value = '';
                }

                //localStorage.setItem('selected_entries_all_' + showcaseID, '');

                allSelectedRow.style.display = 'none';

                if (selectedPageCount >= entriesPerPage) {
                    pageSelectedRow.style.display = 'block';

                    pageSelectedRow.querySelector('strong').textContent = entriesPerPage.toString();
                } else {
                    pageSelectedRow.style.display = 'none';
                }
            }
        });
    });

    const selectAllButton = document.querySelectorAll('.select_all_entries');

    selectAllButton.forEach(button => {
        if (selectedCount >= entriesTotalCount) {
            button.style.display = 'none';

            pageSelectedRow.style.display = 'none';
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();

            selectAllButton.forEach(button => {
                button.style.display = 'none';

                pageSelectedRow.style.display = 'none';
            });

            masterCheckbox.checked = true;

            pageCheckboxes.forEach(checkbox => {
                checkbox.checked = true;

                const closestParent = checkbox.closest('.inline_row');

                if (closestParent) {
                    closestParent.classList.add('trow_selected');
                }
            });

            selectedAllInput.value = '1';

            selectedInput.value = '';

            localStorage.setItem('selected_entries_all_' + showcaseID, '1');

            localStorage.setItem('unselected_entries_' + showcaseID, '');

            counterElement.textContent = entriesTotalCount.toString();

            pageSelectedRow.style.display = 'hide';

            allSelectedRow.style.display = 'block';

            allSelectedRow.querySelector('strong').textContent = entriesTotalCount.toString();

            console.log(entriesPerPage, entriesTotalCount);
        });
    });

    const clearAllButton = document.querySelectorAll('.selected_entries_clear');

    clearAllButton.forEach(button => {
        button.addEventListener('click', function (event) {
            event.preventDefault();

            masterCheckbox.checked = false;

            pageCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });

            selectedAllInput.value = '0';

            localStorage.setItem('selected_entries_all_' + showcaseID, '');

            localStorage.setItem('selected_entries_' + showcaseID, '');

            localStorage.setItem('unselected_entries_' + showcaseID, '');

            counterElement.textContent = '0';

            allSelectedRow.style.display = 'none';

            pageSelectedRow.style.display = 'none';

            document.querySelectorAll('.inline_row').forEach(checkbox => {
                const closestParent = checkbox.closest('.inline_row');

                if (closestParent) {
                    closestParent.classList.remove('trow_selected');
                }
            });
        });
    });

    const form = document.getElementById('inline_moderation_form');

    form.addEventListener('submit', function (event) {
        const moderationAction = form.querySelector('select[name="moderate"]');

        const selectedAllEntries = form.querySelector('input[name="selected_all_entries"]');

        const selectedEntries = form.querySelector('input[name="selected_entries"]');

        if (!moderationAction.value.trim()) {
            event.preventDefault();

            $.jGrowl(lang.inline_moderation_no_tool_selected, {theme: 'jgrowl_error'});
        } else if (!selectedAllEntries.value.trim() && !selectedEntries.value.trim()) {
            event.preventDefault();

            $.jGrowl(lang.inline_moderation_no_entries_selected, {theme: 'jgrowl_error'});
        }
    });
});