@php
    $prefix = $prefix ?? 'teacher_';
    $selectedOfficeId = old('office_location_id', $selectedOfficeId ?? null);
    $selectedOfficeLabel = old('office_location', $selectedOfficeLabel ?? '');
    $selectedSpecs = old('specializations', $selectedSpecs ?? []);
    if (is_string($selectedSpecs)) {
        $selectedSpecs = array_values(array_filter(array_map('trim', explode(',', $selectedSpecs))));
    }
    $biographyVal = old('biography', $biographyVal ?? '');
@endphp

<div class="col-12" data-teacher-profile-widget="{{ $prefix }}">
    <div class="row g-3">
        <!-- Office Location (Searchable Grouped Dropdown, Optional) -->
        <div class="col-md-6">
            <label for="{{ $prefix }}office_search" class="form-label fw-semibold">
                Office Location <span class="text-muted fw-normal small">(Optional)</span>
            </label>
            <input type="hidden" name="office_location_id" id="{{ $prefix }}office_location_id" value="{{ $selectedOfficeId }}">
            <input type="hidden" name="office_location" id="{{ $prefix }}office_location_text" value="{{ $selectedOfficeLabel }}">

            <div class="dropdown w-100">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-building"></i></span>
                    <button type="button"
                        id="{{ $prefix }}office_toggle"
                        class="form-select text-start bg-white text-truncate"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false">
                        <span id="{{ $prefix }}office_display" class="{{ $selectedOfficeLabel ? 'text-dark' : 'text-muted' }}">
                            {{ $selectedOfficeLabel ?: 'Select or search office by building / room...' }}
                        </span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary {{ $selectedOfficeLabel ? '' : 'd-none' }}" id="{{ $prefix }}office_clear" title="Clear office location">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="dropdown-menu p-2 shadow-sm w-100" style="max-height: 320px; overflow-y: auto; z-index: 1065;">
                    <div class="mb-2">
                        <input type="search"
                            id="{{ $prefix }}office_search"
                            class="form-control form-control-sm"
                            placeholder="Search building name, room, or office..."
                            autocomplete="off">
                    </div>

                    <button type="button" class="dropdown-item small text-muted py-1 rounded {{ $prefix }}office-none">
                        None / Leave empty (Optional)
                    </button>
                    <div class="dropdown-divider my-1"></div>

                    <div id="{{ $prefix }}office_groups_container">
                        @foreach($officeGroups ?? [] as $group)
                            <div class="office-building-group" data-building="{{ mb_strtolower($group['building']) }}">
                                <div class="px-2 pt-2 pb-1 small fw-bold text-primary text-uppercase">{{ $group['building'] }}</div>
                                @foreach($group['rooms'] as $room)
                                    <button type="button"
                                        class="dropdown-item small rounded d-flex justify-content-between align-items-center ps-3 office-room-option"
                                        data-id="{{ $room['id'] }}"
                                        data-building="{{ mb_strtolower($room['building']) }}"
                                        data-room="{{ mb_strtolower($room['room_number']) }}"
                                        data-label="{{ $room['display_name'] }}"
                                        data-search="{{ mb_strtolower($room['building'] . ' ' . $room['room_number'] . ' ' . $room['display_name']) }}">
                                        <span><i class="bi bi-door-open me-2 text-muted"></i>{{ $room['room_number'] }}</span>
                                        <span class="badge bg-light text-muted border">{{ $room['building'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>

                    <div id="{{ $prefix }}office_empty" class="small text-muted text-center py-2 d-none">
                        No matching rooms found.
                    </div>

                    <div class="dropdown-divider my-2"></div>
                    <div class="px-1">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100" id="{{ $prefix }}office_show_new">
                            <i class="bi bi-plus-lg me-1"></i>Add new office location
                        </button>
                        <div id="{{ $prefix }}office_new_box" class="mt-2 p-2 bg-light rounded border d-none">
                            <div class="mb-1">
                                <input type="text" id="{{ $prefix }}new_building" class="form-control form-control-sm" placeholder="Building (e.g. Building A)" maxlength="100">
                            </div>
                            <div class="mb-2">
                                <input type="text" id="{{ $prefix }}new_room" class="form-control form-control-sm" placeholder="Room (e.g. Room 305)" maxlength="60">
                            </div>
                            <div class="d-flex justify-content-end gap-1">
                                <button type="button" class="btn btn-sm btn-light" id="{{ $prefix }}office_cancel_new">Cancel</button>
                                <button type="button" class="btn btn-sm btn-primary" id="{{ $prefix }}office_save_new">Use location</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Specializations (Searchable Multi-select Chips/Tags, Optional) -->
        <div class="col-md-6">
            <label for="{{ $prefix }}spec_search" class="form-label fw-semibold">
                Specialization <span class="text-muted fw-normal small">(Optional, multiple allowed)</span>
            </label>

            <div id="{{ $prefix }}spec_chips" class="d-flex flex-wrap gap-1 mb-2">
                @foreach($selectedSpecs as $specName)
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1 spec-chip" data-name="{{ $specName }}">
                        <span>{{ $specName }}</span>
                        <button type="button" class="btn-close" style="font-size: 0.6rem;" aria-label="Remove {{ $specName }}"></button>
                        <input type="hidden" name="specializations[]" value="{{ $specName }}">
                    </span>
                @endforeach
            </div>

            <div class="dropdown w-100">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-tags"></i></span>
                    <button type="button"
                        id="{{ $prefix }}spec_toggle"
                        class="form-select text-start bg-white text-muted"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false">
                        Search or add specializations...
                    </button>
                </div>

                <div class="dropdown-menu p-2 shadow-sm w-100" style="max-height: 300px; overflow-y: auto; z-index: 1065;">
                    <div class="input-group input-group-sm mb-2">
                        <input type="search"
                            id="{{ $prefix }}spec_search"
                            class="form-control"
                            placeholder="Filter or type new specialization..."
                            autocomplete="off">
                        <button type="button" class="btn btn-outline-primary" id="{{ $prefix }}spec_add_custom" title="Add specialization">
                            <i class="bi bi-plus-lg"></i> Add
                        </button>
                    </div>
                    <div id="{{ $prefix }}spec_options">
                        @foreach($specializations ?? [] as $spec)
                            <button type="button"
                                class="dropdown-item small rounded d-flex justify-content-between align-items-center spec-option"
                                data-name="{{ $spec->name }}"
                                data-search="{{ mb_strtolower($spec->name) }}">
                                <span>{{ $spec->name }}</span>
                                <i class="bi bi-check2 text-primary d-none spec-check"></i>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Biography (Multiline Unicode UTF-8 Khmer + English, Optional) -->
        <div class="col-12">
            <label for="{{ $prefix }}biography" class="form-label fw-semibold">
                Biography <span class="text-muted fw-normal small">(Optional · Khmer &amp; English supported)</span>
            </label>
            <textarea
                id="{{ $prefix }}biography"
                name="biography"
                rows="4"
                maxlength="2000"
                class="form-control"
                placeholder="Optional — Enter academic background, teaching focus, degrees, or research interests (supports Khmer and English)...">{{ $biographyVal }}</textarea>
            <div class="form-text">Up to 2,000 characters. Khmer and English Unicode text is supported.</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('[data-teacher-profile-widget="{{ $prefix }}"]');
    if (!root) return;

    // Office Location Search & Grouped Selector
    const officeIdInput = document.getElementById('{{ $prefix }}office_location_id');
    const officeTextInput = document.getElementById('{{ $prefix }}office_location_text');
    const officeDisplay = document.getElementById('{{ $prefix }}office_display');
    const officeClearBtn = document.getElementById('{{ $prefix }}office_clear');
    const officeSearch = document.getElementById('{{ $prefix }}office_search');
    const officeGroupsContainer = document.getElementById('{{ $prefix }}office_groups_container');
    const officeEmpty = document.getElementById('{{ $prefix }}office_empty');
    const showNewBtn = document.getElementById('{{ $prefix }}office_show_new');
    const newBox = document.getElementById('{{ $prefix }}office_new_box');
    const newBuilding = document.getElementById('{{ $prefix }}new_building');
    const newRoom = document.getElementById('{{ $prefix }}new_room');

    function selectOffice(id, label) {
        officeIdInput.value = id || '';
        officeTextInput.value = label || '';
        if (label) {
            officeDisplay.textContent = label;
            officeDisplay.classList.remove('text-muted');
            officeDisplay.classList.add('text-dark');
            officeClearBtn.classList.remove('d-none');
        } else {
            officeDisplay.textContent = 'Select or search office by building / room...';
            officeDisplay.classList.add('text-muted');
            officeDisplay.classList.remove('text-dark');
            officeClearBtn.classList.add('d-none');
        }
    }

    root.querySelector('.{{ $prefix }}office-none')?.addEventListener('click', () => selectOffice('', ''));
    officeClearBtn?.addEventListener('click', () => selectOffice('', ''));

    officeGroupsContainer?.addEventListener('click', function (e) {
        const btn = e.target.closest('.office-room-option');
        if (!btn) return;
        selectOffice(btn.dataset.id, btn.dataset.label);
    });

    officeSearch?.addEventListener('input', function () {
        const term = officeSearch.value.trim().toLowerCase();
        let anyVisible = false;
        officeGroupsContainer.querySelectorAll('.office-building-group').forEach(function (group) {
            let groupMatches = false;
            group.querySelectorAll('.office-room-option').forEach(function (opt) {
                const match = term === '' || (opt.dataset.search || '').includes(term);
                opt.classList.toggle('d-none', !match);
                if (match) groupMatches = true;
            });
            group.classList.toggle('d-none', !groupMatches);
            if (groupMatches) anyVisible = true;
        });
        officeEmpty?.classList.toggle('d-none', anyVisible);
    });

    showNewBtn?.addEventListener('click', () => newBox?.classList.toggle('d-none'));
    document.getElementById('{{ $prefix }}office_cancel_new')?.addEventListener('click', () => newBox?.classList.add('d-none'));
    document.getElementById('{{ $prefix }}office_save_new')?.addEventListener('click', function () {
        const b = (newBuilding?.value || '').trim();
        let r = (newRoom?.value || '').trim();
        if (!b || !r) return;
        if (/^\d+[A-Za-z]?$/.test(r)) r = 'Room ' + r;
        const label = b + ', ' + r;
        selectOffice('', label);
        newBuilding.value = '';
        newRoom.value = '';
        newBox.classList.add('d-none');
    });

    // Multi-Specialization Search & Chips
    const specChipsContainer = document.getElementById('{{ $prefix }}spec_chips');
    const specSearch = document.getElementById('{{ $prefix }}spec_search');
    const specOptions = document.getElementById('{{ $prefix }}spec_options');
    const specAddCustom = document.getElementById('{{ $prefix }}spec_add_custom');

    function selectedSpecNames() {
        return Array.from(specChipsContainer.querySelectorAll('.spec-chip')).map(el => (el.dataset.name || '').trim());
    }

    function syncSpecChecks() {
        const current = selectedSpecNames().map(s => s.toLowerCase());
        specOptions.querySelectorAll('.spec-option').forEach(function (opt) {
            const checked = current.includes((opt.dataset.name || '').toLowerCase());
            opt.querySelector('.spec-check')?.classList.toggle('d-none', !checked);
        });
    }

    function addSpecChip(name) {
        const clean = name.trim();
        if (!clean) return;
        if (selectedSpecNames().some(s => s.toLowerCase() === clean.toLowerCase())) return;

        const chip = document.createElement('span');
        chip.className = 'badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1 spec-chip';
        chip.dataset.name = clean;

        const label = document.createElement('span');
        label.textContent = clean;

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close';
        closeBtn.style.fontSize = '0.6rem';
        closeBtn.setAttribute('aria-label', 'Remove ' + clean);

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'specializations[]';
        hidden.value = clean;

        chip.append(label, closeBtn, hidden);
        specChipsContainer.appendChild(chip);
        syncSpecChecks();
    }

    function removeSpecChip(name) {
        specChipsContainer.querySelectorAll('.spec-chip').forEach(function (chip) {
            if ((chip.dataset.name || '').toLowerCase() === name.toLowerCase()) {
                chip.remove();
            }
        });
        syncSpecChecks();
    }

    specChipsContainer?.addEventListener('click', function (e) {
        if (e.target.classList.contains('btn-close')) {
            const chip = e.target.closest('.spec-chip');
            if (chip) {
                chip.remove();
                syncSpecChecks();
            }
        }
    });

    specOptions?.addEventListener('click', function (e) {
        const opt = e.target.closest('.spec-option');
        if (!opt) return;
        const name = opt.dataset.name;
        const exists = selectedSpecNames().some(s => s.toLowerCase() === name.toLowerCase());
        if (exists) {
            removeSpecChip(name);
        } else {
            addSpecChip(name);
        }
    });

    specSearch?.addEventListener('input', function () {
        const term = specSearch.value.trim().toLowerCase();
        specOptions.querySelectorAll('.spec-option').forEach(function (opt) {
            const match = term === '' || (opt.dataset.search || '').includes(term);
            opt.classList.toggle('d-none', !match);
        });
    });

    specAddCustom?.addEventListener('click', function () {
        const val = (specSearch?.value || '').trim();
        if (!val) return;
        addSpecChip(val);
        specSearch.value = '';
        specSearch.dispatchEvent(new Event('input'));
    });

    syncSpecChecks();
});
</script>
