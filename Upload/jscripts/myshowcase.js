document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.quick_edit_button');

    editButtons.forEach(button => {
        button.classList.toggle('hidden');

        button.addEventListener('click', function () {
            const entry_slug = this.dataset.entrySlug;
            const view_url = this.dataset.viewUrl;
            const update_url = this.dataset.updateUrl;
            const entry = document.getElementById(`entryBody${entry_slug}`);
            const message_div = entry.querySelector('div.post__message');
            const update_note_div = entry.querySelector('.post__edit-reason');

            fetch(view_url)
                .then(response => response.json())
                .then(data => {
                    message_div.innerHTML = '';
                    const container = document.createElement('div');

                    const textarea = document.createElement('textarea');
                    textarea.focus();
                    textarea.value = data.message || '';
                    textarea.rows = 12;
                    textarea.name = 'message';
                    textarea.style.width = '1106px';
                    textarea.id = `quick_edit_${entry_slug}_message`;

                    message_div.appendChild(textarea);

                    if (window.sceditor !== undefined) {
                        const new_editor_instance = sceditor.create(textarea, opt_editor);
                    } else {
                        console.log('SCEditor is not loaded.');
                    }

                    const reason_input = document.createElement('input');
                    if (entries_enable_update_notes) {
                        reason_input.type = 'text';
                        reason_input.value = data.update_note || '';
                        reason_input.size = 40;
                        reason_input.maxlength = 150;
                        reason_input.placeholder = 'Edit reason...';
                        reason_input.className = 'textbox';
                        reason_input.name = 'update_note';
                        reason_input.style.margin = '6px 0';
                        reason_input.id = `quick_edit_${entry_slug}_reason`;
                        message_div.appendChild(reason_input);
                    }

                    const save_button = document.createElement('button');
                    save_button.type = 'submit';
                    save_button.textContent = 'Save Changes';
                    message_div.appendChild(save_button);

                    const cancel_button = document.createElement('button');
                    cancel_button.type = 'cancel';
                    cancel_button.textContent = 'Cancel Edit';
                    message_div.appendChild(cancel_button);

                    const original_message = message_div.innerHTML;

                    //message_div.innerHTML = '';
                    //container.appendChild(container);

                    save_button.addEventListener('click', () => {
                        const form_data = new FormData();

                        form_data.append('message', textarea.value);

                        if (entries_enable_update_notes) {
                            form_data.append('update_note', reason_input.value);
                        }

                        fetch(update_url, {
                            method: 'POST',
                            body: form_data
                        })
                            .then(response => response.json())
                            .then(updated_data => {
                                console.log([updated_data]);
                                message_div.innerHTML = updated_data.message;

                                textarea.replaceWith('');

                                reason_input.replaceWith('');

                                cancel_button.replaceWith('');

                                save_button.replaceWith('');
                            });
                    });

                    cancel_button.addEventListener('click', () => {
                        message_div.innerHTML = original_message;

                        new_editor_instance.destroy();
                    });
                });

            return false;
        });
    });
});