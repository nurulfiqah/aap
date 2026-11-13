document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('case_type_id');
    var info = document.getElementById('ct_info');
    var valType = document.getElementById('value_type');
    var deptFilter = document.getElementById('department_id_filter');

    // Customer search typeahead - attached directly to BOTH Customer Name
    // and Membership ID, so searching by name/IC/membership id (against the
    // shared `customer` table - mamabe uses the same table for its own
    // name/IC search) works no matter which of the two fields you start
    // typing in. Picking a result fills both fields, then locks them
    // (readonly) so a verified match can't be silently hand-edited into
    // something that no longer matches any real customer - the Refresh
    // button on the Case Details card header clears every field in this
    // section (Customer Name/Membership ID included, unlocking them too),
    // without touching anything else on the form.
    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    // Shared by Membership ID, Transaction No, and Bank Account Number -
    // all three only ever hold digits, so anything else is stripped as it's
    // typed (covers manual typing; a picked search result's c_id is already
    // numeric).
    function digitsOnlyFilter(el) {
        if (!el) return;
        el.addEventListener('input', function () {
            var digitsOnly = el.value.replace(/\D/g, '');
            if (digitsOnly !== el.value) el.value = digitsOnly;
        });
    }
    digitsOnlyFilter(document.getElementById('bank-account-number-input'));

    (function () {
        var nameInput = document.getElementById('customer-name-input');
        var membershipInput = document.getElementById('customer-membership-input');
        var refreshBtn = document.getElementById('case-details-refresh-btn');
        var transactionInput = document.getElementById('case-details-transaction-ref');
        var calculatedValueInput = document.getElementById('case-details-calculated-value');
        var recommendedOutcomeInput = document.getElementById('case-details-recommended-outcome');
        var nameLabel = document.getElementById('customer-name-label');
        var membershipField = document.getElementById('customer-membership-field');
        var typeInput = document.getElementById('beneficiary-type-input');
        var typeCustomerBtn = document.getElementById('beneficiary-type-customer');
        var typeStaffBtn = document.getElementById('beneficiary-type-staff');
        if (!nameInput || !membershipInput) return;

        function lockCustomerFields() {
            nameInput.readOnly = true;
            membershipInput.readOnly = true;
        }

        function unlockCustomerFields() {
            nameInput.readOnly = false;
            membershipInput.readOnly = false;
        }

        // Customer/Staff toggle - a case normally belongs to a registered
        // customer, but sometimes it's an internal claim/refund that belongs
        // to a staff member instead. Switching mode re-labels the Name field
        // and points the typeahead at the `staff` table instead of
        // `customer` (see aap_search_customer.php's type=staff branch). For
        // Staff, the ID field is filled automatically from the picked search
        // result but hidden - staff are identified by name only on this
        // form, there's no reason to surface their internal staff.id. The
        // two underlying form fields/DB columns stay the same either way
        // (membership_id just holds staff.id as text for a Staff case), and
        // a hidden-but-rendered-none field is excluded from the browser's
        // own required-field validation, so the empty display doesn't block
        // submit once a result is picked.
        function setBeneficiaryType(type) {
            typeInput.value = type;
            var isStaff = type === 'staff';
            typeCustomerBtn.classList.toggle('active', !isStaff);
            typeStaffBtn.classList.toggle('active', isStaff);
            nameLabel.firstChild.textContent = (isStaff ? (nameLabel.dataset.staffLabel || 'Staff Name') : (nameLabel.dataset.customerLabel || 'Customer Name')) + ' ';
            if (membershipField) membershipField.style.display = isStaff ? 'none' : '';
            nameInput.value = '';
            membershipInput.value = '';
            unlockCustomerFields();
        }

        if (typeCustomerBtn && typeStaffBtn) {
            typeCustomerBtn.addEventListener('click', function () { setBeneficiaryType('customer'); });
            typeStaffBtn.addEventListener('click', function () { setBeneficiaryType('staff'); });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                nameInput.value = '';
                membershipInput.value = '';
                unlockCustomerFields();
                if (transactionInput) transactionInput.value = '';
                if (calculatedValueInput) calculatedValueInput.value = '';
                if (valType) valType.value = 'cash';
                if (recommendedOutcomeInput) recommendedOutcomeInput.value = '';
                nameInput.focus();
            });
        }

        digitsOnlyFilter(membershipInput);
        digitsOnlyFilter(transactionInput);

        function initCustomerLookup(input, results) {
            if (!input || !results) return;
            var debounceTimer = null;

            function hideResults() {
                results.style.display = 'none';
                results.innerHTML = '';
            }

            function search(q) {
                var url = 'aap_search_customer.php?q=' + encodeURIComponent(q)
                    + (typeInput && typeInput.value === 'staff' ? '&type=staff' : '');
                fetch(url)
                    .then(function (r) { return r.json(); })
                    .then(function (rows) { renderResults(rows); })
                    .catch(function () { hideResults(); });
            }

            function renderResults(rows) {
                if (!rows || !rows.length) { hideResults(); return; }
                results.innerHTML = '';
                rows.forEach(function (row) {
                    var li = document.createElement('li');
                    li.style.cursor = 'pointer';
                    li.style.display = 'block';
                    var line = typeInput && typeInput.value === 'staff'
                        ? row.customer_name
                        : (row.ic ? (row.ic + ': ' + row.customer_name + ', ID: ' + (row.c_id || '—')) : (row.customer_name + ', ID: ' + (row.c_id || '—')));
                    li.innerHTML = escapeHtml(line);
                    // Preventing the mousedown's default action stops the
                    // browser from blurring the input the instant the mouse
                    // goes down on a non-focusable element like <li>.
                    li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                    li.addEventListener('click', function () {
                        nameInput.value = row.customer_name || '';
                        membershipInput.value = row.c_id || '';
                        hideResults();
                        lockCustomerFields();
                    });
                    results.appendChild(li);
                });
                results.style.display = 'block';
            }

            input.addEventListener('input', function () {
                var q = input.value.trim();
                clearTimeout(debounceTimer);
                if (q.length < 2) { hideResults(); return; }
                debounceTimer = setTimeout(function () { search(q); }, 200);
            });

            document.addEventListener('click', function (e) {
                if (e.target !== input && !results.contains(e.target)) hideResults();
            });
        }

        initCustomerLookup(nameInput, document.getElementById('customer-name-results'));
        initCustomerLookup(membershipInput, document.getElementById('customer-membership-results'));
    })();

    function refresh() {
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) {
            info.style.display = 'none';
            applyCaseDetailsLayout(null);
            return;
        }

        // Under the wildcard "AAP" department, the locked Department field
        // starts showing "AAP" (the Fixit routing artifact, not a real
        // department a Case Type ever belongs to) - once a Case Type is
        // actually picked, switch it to show THAT Case Type's own real
        // department instead, so the field reflects what the case is
        // actually filed under rather than how it got here. No-op outside
        // the wildcard case: every option there already shares the one
        // real department the field's already locked to.
        if (deptFilter && opt.dataset.departmentId) {
            var deptOpt = deptFilter.querySelector('option[value="' + opt.dataset.departmentId + '"]');
            if (deptOpt) {
                deptFilter.value = opt.dataset.departmentId;
                var hint = document.getElementById('department_filter_hint');
                if (hint && hint.dataset.wildcard === '1') {
                    hint.textContent = 'This case will be filed under ' + deptOpt.textContent.trim() + '.';
                }
            }
        }

        var lines = [];
        if (opt.dataset.desc) lines.push(opt.dataset.desc);
        if (opt.dataset.physical === '1') lines.push('<strong>Verification Reference</strong> before this case can reach the approval gate.');
        lines.push('Approved by the staff assigned to this Case Type\'s approval gate.');

        // Instruction for User is deliberately NOT repeated here any more -
        // it already shows as "Required Information" on the Original Fixit
        // Report card above (fixit/add_report.php's own required fields,
        // same Case Type instructions) whenever that applies, so showing it
        // a second time in this Case Type info box was pure duplication.

        // Reference template(s) an admin attached to this Case Type
        // (admin/aap_admin.php) - download link(s) so whoever's raising the
        // case can grab the form/doc, fill it in, and upload it back as
        // their own evidence below.
        try {
            var templates = opt.dataset.templates ? JSON.parse(opt.dataset.templates) : [];
        } catch (e) {
            var templates = [];
        }
        if (templates.length) {
            var tplLinks = templates.map(function (t) {
                return (t.remark ? '<span style="color:#6c757d;">' + escapeHtml(t.remark) + ':</span> ' : '')
                    + '<a href="aap_template_download.php?id=' + encodeURIComponent(t.id) + '" target="_blank"><i class="bi bi-file-earmark-arrow-down"></i> ' + escapeHtml(t.file_name) + '</a>';
            });
            lines.push('<strong>Template(s):</strong> ' + tplLinks.join(' &nbsp;|&nbsp; '));
        }

        info.innerHTML = lines.join('<br>');
        info.style.display = 'block';

        applyCaseDetailsLayout(opt);
    }

    // Case Details' field set - normally Form Master's global Show/Hide,
    // Required/Optional, Label config (read once off the <select> itself,
    // data-form-master-config) in a fixed default order. When the picked
    // Case Type has its own non-empty "Instruction for User" list
    // (opt.dataset.orderedPoints, see aapCaseTypeFieldOrder() in
    // aap_lib.php), that list REPLACES Form Master entirely for this Case
    // Type: only listed fields show, every one required, in the list's own
    // order - exactly mirroring Fixit's "Required Information" for the same
    // list. A listed field that isn't one of the known structural ones
    // becomes a plain required text field instead (an "extra" point).
    var fieldsContainer = document.getElementById('case-details-fields-container');
    var bankContainer = document.getElementById('bank-detail-fields-container');
    var bankCard = document.getElementById('bank-detail-card');
    var DEFAULT_ORDER = ['customer_name', 'customer_membership_id', 'transaction_ref', 'calculated_value', 'value_type', 'recommended_outcome'];
    // Keys that can appear in Bank Detail by default but may be pulled into
    // Case Details' ordered list when a Case Type's list names them.
    var BANK_KEYS = ['bank_name', 'bank_account_number', 'bank_account_holder'];
    var ALL_STRUCTURAL_KEYS = DEFAULT_ORDER.concat(BANK_KEYS);
    // staff_name is the exact same physical field/wrap as customer_name
    // (js/aap_add.js's Customer/Staff toggle just relabels it) - never a
    // separate wrap of its own.
    var WRAP_IDS = {
        customer_name: 'field-wrap-customer_name',
        staff_name: 'field-wrap-customer_name',
        customer_membership_id: 'customer-membership-field',
        transaction_ref: 'field-wrap-transaction_ref',
        calculated_value: 'field-wrap-calculated_value',
        value_type: 'field-wrap-value_type',
        recommended_outcome: 'field-wrap-recommended_outcome',
        bank_name: 'field-wrap-bank_name',
        bank_account_number: 'field-wrap-bank_account_number',
        bank_account_holder: 'field-wrap-bank_account_holder'
    };
    var LABEL_IDS = {
        transaction_ref: 'field-label-transaction_ref',
        calculated_value: 'field-label-calculated_value',
        value_type: 'field-label-value_type',
        recommended_outcome: 'field-label-recommended_outcome',
        bank_name: 'field-label-bank_name',
        bank_account_number: 'field-label-bank_account_number',
        bank_account_holder: 'field-label-bank_account_holder'
    };
    var INPUT_IDS = {
        transaction_ref: 'case-details-transaction-ref',
        calculated_value: 'case-details-calculated-value',
        value_type: 'value_type',
        recommended_outcome: 'case-details-recommended-outcome',
        bank_name: 'bank-name-input',
        bank_account_number: 'bank-account-number-input',
        bank_account_holder: 'bank-account-holder-input'
    };
    // Only needed for auto-fill (setFieldValue()) - customer_name/
    // membership_id aren't in INPUT_IDS above since setFieldState() never
    // toggles their required/visible (they're always on), but their values
    // still need filling from the Fixit report's own answers.
    var VALUE_INPUT_IDS = Object.assign({}, INPUT_IDS, {
        customer_name: 'customer-name-input',
        customer_membership_id: 'customer-membership-input'
    });

    // Pre-fills a field from this Fixit report's own "Required Information"
    // answer for the matching point (see the per-<option> data-ordered-points
    // payload's `value`, set server-side in aap_add.php only for the Case
    // Type Fixit actually linked this report to) - so the requester never
    // has to retype what they already gave in Fixit.
    function setFieldValue(key, value) {
        if (value === undefined || value === null || value === '') return;
        var inputId = VALUE_INPUT_IDS[key];
        if (!inputId) return;
        var inputEl = document.getElementById(inputId);
        if (inputEl) inputEl.value = value;
    }

    function setFieldState(key, visible, required, label) {
        var wrapEl = document.getElementById(WRAP_IDS[key]);
        if (!wrapEl) return;
        wrapEl.style.display = visible ? '' : 'none';
        var labelId = LABEL_IDS[key];
        var inputId = INPUT_IDS[key];
        if (labelId) {
            var labelEl = document.getElementById(labelId);
            if (labelEl) labelEl.innerHTML = escapeHtml(label) + (visible && required ? ' <span class="aap-req">*</span>' : '');
        }
        if (inputId) {
            var inputEl = document.getElementById(inputId);
            if (inputEl) inputEl.required = !!(visible && required);
        }
    }

    function buildExtraFieldWrap(point) {
        var wrap = document.createElement('div');
        wrap.className = 'alpro-field';
        var name = 'instruction_answer[' + escapeHtml(point.key.replace(/\./g, '_')) + ']';
        var req = point.required !== false;
        var reqAttr = req ? 'required' : '';
        var inputHtml;
        if (point.type === 'number') {
            inputHtml = '<input class="alpro-input" type="number" step="any" name="' + name + '" ' + reqAttr + '>';
        } else if (point.type === 'date') {
            inputHtml = '<input class="alpro-input" type="date" name="' + name + '" ' + reqAttr + '>';
        } else if (point.type === 'dropdown' && point.options && point.options.length) {
            inputHtml = '<select class="alpro-input" name="' + name + '" ' + reqAttr + '><option value="">Select</option>' +
                point.options.map(function (o) { return '<option value="' + escapeHtml(o) + '">' + escapeHtml(o) + '</option>'; }).join('') +
                '</select>';
        } else {
            inputHtml = '<input class="alpro-input" type="text" name="' + name + '" ' + reqAttr + '>';
        }
        // A point flagged "Attach" (admin/aap_admin.js's builder) needs its
        // own supporting file alongside the answer above - always required
        // once flagged, regardless of the point's own text-answer `required`
        // toggle (aapValidateExtraInstructionFields() in aap_lib.php enforces
        // the same independently server-side).
        var attachHtml = '';
        if (point.requires_attachment) {
            var attachName = 'extra_attachment[' + escapeHtml(point.key.replace(/\./g, '_')) + ']';
            attachHtml = '<div style="margin-top:4px;"><input class="alpro-input" type="file" name="' + attachName + '" required></div>';
        }
        wrap.innerHTML = '<label>' + escapeHtml(point.label) + ' ' + (req ? '<span class="aap-req">*</span>' : '') + '</label>' + inputHtml + attachHtml;
        return wrap;
    }

    function applyCaseDetailsLayout(opt) {
        if (!fieldsContainer) return;
        // Drop any previously-created extra-point inputs - everything else
        // (the real structural fields) gets relocated/reused below, never
        // cloned, so typeahead bindings etc. survive.
        fieldsContainer.querySelectorAll('[data-extra-point]').forEach(function (el) { el.remove(); });
        // Same for Attach file inputs added onto a Form Master field's own
        // wrap below - those wraps can live outside fieldsContainer (Bank
        // Detail), hence a document-wide lookup.
        document.querySelectorAll('[data-matched-attach]').forEach(function (el) { el.remove(); });

        // Bank Detail's two movable fields always start back home before
        // this call decides whether to pull them into Case Details -
        // deterministic every time, no stale state from a previous pick.
        if (bankContainer) {
            BANK_KEYS.forEach(function (key) {
                var wrapEl = document.getElementById(WRAP_IDS[key]);
                if (wrapEl && wrapEl.parentNode !== bankContainer) bankContainer.appendChild(wrapEl);
            });
        }

        var formMasterConfig = {};
        if (sel.dataset.formMasterConfig) {
            try { formMasterConfig = JSON.parse(sel.dataset.formMasterConfig) || {}; } catch (e) { formMasterConfig = {}; }
        }

        var orderedPoints = [];
        if (opt && opt.dataset.orderedPoints) {
            try { orderedPoints = JSON.parse(opt.dataset.orderedPoints) || []; } catch (e) { orderedPoints = []; }
        }

        if (!orderedPoints.length) {
            // No list for this Case Type (or none picked) - Form Master's
            // config, default order, Bank fields stay in Bank Detail.
            DEFAULT_ORDER.forEach(function (key) {
                var c = formMasterConfig[key] || { visible: true, required: false, label: key };
                setFieldState(key, c.visible, c.required, c.label);
                var wrapEl = document.getElementById(WRAP_IDS[key]);
                if (wrapEl) fieldsContainer.appendChild(wrapEl);
            });
            BANK_KEYS.forEach(function (key) {
                var c = formMasterConfig[key] || { visible: true, required: false, label: key };
                setFieldState(key, c.visible, c.required, c.label);
            });
            // No list - Bank Detail always shows (today's default).
            if (bankCard) bankCard.style.display = '';
            return;
        }

        // Replace mode - walk the list once, moving/relabeling each matched
        // structural field into position and inserting a fresh text input
        // for each non-matching ("extra") point, in exact list order.
        var matchedKeys = {};
        orderedPoints.forEach(function (p) {
            var key = p.matched_field_key;
            if (key) {
                // staff_name shares customer_name's one physical wrap.
                var realKey = key === 'staff_name' ? 'customer_name' : key;
                matchedKeys[realKey] = true;
                setFieldState(realKey, true, true, p.label);
                setFieldValue(realKey, p.value);
                var wrapEl = document.getElementById(WRAP_IDS[key]);
                if (wrapEl) {
                    fieldsContainer.appendChild(wrapEl);
                    // "Attach" on a Form Master point - same extra_attachment[]
                    // input buildExtraFieldWrap() gives an extra point, just
                    // appended to the real field's own wrap.
                    if (p.requires_attachment) {
                        var attachEl = document.createElement('div');
                        attachEl.setAttribute('data-matched-attach', '1');
                        attachEl.style.marginTop = '4px';
                        attachEl.innerHTML = '<input class="alpro-input" type="file" name="extra_attachment[' + escapeHtml(p.key.replace(/\./g, '_')) + ']" required>';
                        wrapEl.appendChild(attachEl);
                    }
                }
            } else {
                var extraWrap = buildExtraFieldWrap(p);
                extraWrap.setAttribute('data-extra-point', '1');
                if (p.value) extraWrap.querySelector('input, select').value = p.value;
                fieldsContainer.appendChild(extraWrap);
            }
        });

        // Identity fields always stay visible+required even if this list
        // never mentions them (the case still needs linking to a real
        // customer/staff) - and keep their place if not already positioned
        // by a match above.
        ['customer_name', 'customer_membership_id'].forEach(function (key) {
            if (!matchedKeys[key]) {
                var c = formMasterConfig[key] || { visible: true, required: true, label: key };
                setFieldState(key, true, true, c.label);
                var wrapEl = document.getElementById(WRAP_IDS[key]);
                if (wrapEl && wrapEl.parentNode !== fieldsContainer) fieldsContainer.appendChild(wrapEl);
            }
            matchedKeys[key] = true; // always protected from the hide-pass below, matched or not
        });

        // Anything structural not in the list (and not an identity field)
        // is hidden entirely - the list fully replaces the field set.
        ALL_STRUCTURAL_KEYS.forEach(function (key) {
            if (!matchedKeys[key]) setFieldState(key, false, false, '');
        });

        // Whole Bank Detail card is pointless to show when none of its 3
        // fields are in this Case Type's list - hide it entirely rather
        // than leaving an empty card with just a title.
        if (bankCard) {
            var anyBankMatched = BANK_KEYS.some(function (key) { return !!matchedKeys[key]; });
            bankCard.style.display = anyBankMatched ? '' : 'none';
        }
    }

    sel.addEventListener('change', refresh);
    refresh();

    // Live preview of chosen evidence files before submit, each clickable
    // (blob URL) with its own remove button - same behaviour as aap_update.php's
    // "Add More Evidence" preview. pendingFiles accumulates across multiple
    // "Choose files" picks since a native file input replaces its selection
    // each time; the input's FileList is rebuilt from it via DataTransfer.
    var evidenceFileInput = document.getElementById('evidence-file-input');
    var evidenceFilePreview = document.getElementById('evidence-file-preview');
    // Each entry is { file, url } - the blob URL is created exactly once per
    // file (not on every re-render) and explicitly revoked when the file is
    // removed or the page unloads, so picking/removing files repeatedly
    // doesn't leak blob memory and eventually freeze the tab.
    var pendingFiles = [];

    function syncFileInput() {
        var dt = new DataTransfer();
        pendingFiles.forEach(function (p) { dt.items.add(p.file); });
        evidenceFileInput.files = dt.files;
    }

    function renderFilePreview() {
        evidenceFilePreview.innerHTML = '';
        pendingFiles.forEach(function (entry, index) {
            var li = document.createElement('li');

            var nameLink = document.createElement('a');
            nameLink.href = entry.url;
            nameLink.target = '_blank';
            nameLink.rel = 'noopener';
            nameLink.innerHTML = '<i class="bi bi-file-earmark-arrow-up"></i> ';
            nameLink.appendChild(document.createTextNode(entry.file.name));
            li.appendChild(nameLink);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.title = 'Remove from upload';
            removeBtn.style.cssText = 'background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.addEventListener('click', function () {
                URL.revokeObjectURL(entry.url);
                pendingFiles.splice(index, 1);
                syncFileInput();
                renderFilePreview();
            });
            li.appendChild(removeBtn);

            evidenceFilePreview.appendChild(li);
        });
    }

    evidenceFileInput.addEventListener('change', function () {
        Array.prototype.forEach.call(evidenceFileInput.files, function (f) {
            pendingFiles.push({ file: f, url: URL.createObjectURL(f) });
        });
        syncFileInput();
        renderFilePreview();
    });

    // Release every still-live blob URL on the way out, in case the form is
    // abandoned (navigated away from) with files still pending.
    window.addEventListener('beforeunload', function () {
        pendingFiles.forEach(function (p) { URL.revokeObjectURL(p.url); });
    });
});