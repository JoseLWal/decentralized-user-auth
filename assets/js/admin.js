document.addEventListener('DOMContentLoaded', function () {

    /** Connect Account **/
    const connectButton = document.getElementById('dua-connect-button');
    if (connectButton) {
        connectButton.addEventListener('click', function () {
            const statusEl = document.getElementById('dua-link-status');
            statusEl.textContent = 'Authenticating…';

            // Prepare form data for linking account.
            const data = new FormData();
            data.append('action', 'dua_link_user_account');
            data.append('nonce', document.getElementById('dua_nonce').value);
            data.append('site_url', document.getElementById('dua_site_url').value);
            data.append('username', document.getElementById('dua_username').value);
            data.append('password', document.getElementById('dua_password').value);
            data.append('main_user_id', document.getElementById('dua_main_user_id').value);

            // Send link request.
            fetch(ajaxurl, {
                method: 'POST',
                body: data
            })
            .then(res => res.json())
            .then(response => {
                if (response.success && response.data && response.data.account) {
                    const account = response.data.account;

                    // Request login token for linked account.
                    const tokenData = new FormData();
                    tokenData.append('action', 'dua_get_linked_account_token');
                    tokenData.append('user_id', account.ID);
                    tokenData.append('site_id', account.site_id);

                    fetch(ajaxurl, {
                        method: 'POST',
                        body: tokenData
                    })
                    .then(res => res.json())
                    .then(tokenResponse => {
                        if (tokenResponse.success) {
                            const loginUrl = tokenResponse.data.login_url;

                            // Inject linked account row.
                            const rowHtml = `
                                <tr>
                                    <td>${account.site_url}</td>
                                    <td>${account.user_login}</td>
                                    <td>${account.user_email}</td>
                                    <td>
                                        <a href="${loginUrl}" class="button button-secondary" target="_blank">Sign In</a>
                                        <button class="button button-link-delete dua-unlink-user-account" data-user-id="${account.ID}">Unlink</button>
                                    </td>
                                </tr>
                            `;
                            document.getElementById('dua-linked-list').insertAdjacentHTML('beforeend', rowHtml);
                            statusEl.textContent = '✅ ' + response.data.message;

                            // Remove placeholder row if present.
                            const noAccountRow = document.getElementById('no-linked-account');
                            if (noAccountRow) noAccountRow.remove();

                            // Clear input fields.
                            document.querySelectorAll('#link-account-fields-table input').forEach(input => input.value = '');
                        } else {
                            statusEl.textContent = '⚠️ Failed to generate login token.';
                        }
                    });

                } else {
                    statusEl.textContent = '❌ ' + (response.data?.data || 'Linking failed.');
                }
            });
        });
    }

    /** Unlink Account **/
    const linkedList = document.getElementById('dua-linked-list');
    if (linkedList) {
        linkedList.addEventListener('click', function (e) {
            const target = e.target;
            if (target.classList.contains('dua-unlink-user-account')) {
                e.preventDefault();
                if (!confirm('Are you sure you want to unlink this user?')) return;

                // Prepare unlink request.
                const userId = target.getAttribute('data-user-id');
                const data = new FormData();
                data.append('action', 'dua_unlink_user_account');
                data.append('user_id', userId);

                fetch(ajaxurl, {
                    method: 'POST',
                    body: data
                })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        // Remove row from table.
                        const row = target.closest('tr');
                        if (row) row.remove();

                        document.getElementById('dua-link-status').textContent = '';

                        // Re-insert placeholder if no accounts remain.
                        if (linkedList.querySelectorAll('tr').length === 0) {
                            linkedList.insertAdjacentHTML('beforeend', `
                                <tr id="no-linked-account">
                                    <td colspan="4"><em>No accounts linked yet.</em></td>
                                </tr>
                            `);
                        }
                    } else {
                        alert(response.data || 'Unlink failed.');
                    }
                });
            }
        });
    }

    /** Modal Handling **/
    document.querySelectorAll('.dua-open-modal').forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('duaModal').classList.add('is-visible');
        });
    });

    document.querySelectorAll('.dua-close-modal').forEach(button => {
        button.addEventListener('click', function () {
            document.getElementById('duaModal').classList.remove('is-visible');
        });
    });

    /** Roaming Secret Key Generator **/
    const generateKeyBtn = document.getElementById('dua-generate-secret-key');
    const secretKeyInput = document.getElementById('dua_roaming_secret_key');

    if (generateKeyBtn && secretKeyInput) {
        generateKeyBtn.addEventListener('click', function () {
            // Generate a secure 32-character key.
            const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()_+-=';
            let key = '';
            for (let i = 0; i < 32; i++) {
                key += charset.charAt(Math.floor(Math.random() * charset.length));
            }

            // Populate the input field with the new key.
            secretKeyInput.value = key;
        });
    }

    /** Compile Button Redirect **/
    const compileBtn = document.getElementById('dua-compile-code-button');
    if (compileBtn) {
        compileBtn.addEventListener('click', function () {
            // Redirect to compile action.
            const redirectUrl = compileBtn.getAttribute('data-redirect');
            if (redirectUrl) {
                window.location.href = redirectUrl;
            }
        });
    }

});
