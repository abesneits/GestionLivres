/**
 * Widget de saisie de tags (chips + suggestions).
 * Usage : initTagInput(containerEl, hiddenInputEl, allTags)
 *  - containerEl : élément .tag-input-container contenant un input.tag-input-field
 *  - hiddenInputEl : input hidden dont la valeur (CSV) est synchronisée avec les chips
 *  - allTags : tableau de tags existants utilisé pour les suggestions
 */
function initTagInput(container, hiddenInput, allTags) {
    allTags = allTags || [];

    var tags = (hiddenInput.value || '')
        .split(',')
        .map(function (t) { return t.trim(); })
        .filter(function (t) { return t.length > 0; });

    var textInput = container.querySelector('.tag-input-field');
    var suggestionsBox = null;

    function sync() {
        hiddenInput.value = tags.join(',');
    }

    function renderChips() {
        var chips = container.querySelectorAll('.tag-chip');
        for (var i = 0; i < chips.length; i++) {
            chips[i].remove();
        }

        tags.forEach(function (tag, index) {
            var chip = document.createElement('span');
            chip.className = 'tag-chip';
            chip.textContent = tag;

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'tag-chip-remove';
            removeBtn.textContent = '×';
            removeBtn.setAttribute('aria-label', 'Retirer le tag ' + tag);
            removeBtn.addEventListener('click', function () {
                var idx = tags.indexOf(tag);
                if (idx !== -1) {
                    tags.splice(idx, 1);
                    renderChips();
                    sync();
                }
            });

            chip.appendChild(removeBtn);
            container.insertBefore(chip, textInput);
        });
    }

    function hideSuggestions() {
        if (suggestionsBox) {
            suggestionsBox.remove();
            suggestionsBox = null;
        }
    }

    function addTag(value) {
        var tag = value.trim();
        if (!tag) return;
        var exists = tags.some(function (t) { return t.toLowerCase() === tag.toLowerCase(); });
        if (exists) return;
        tags.push(tag);
        renderChips();
        sync();
        textInput.value = '';
        hideSuggestions();
    }

    function showSuggestions(query) {
        hideSuggestions();
        var q = query.trim().toLowerCase();
        var matches = allTags.filter(function (t) {
            var alreadyUsed = tags.some(function (existing) {
                return existing.toLowerCase() === t.toLowerCase();
            });
            if (alreadyUsed) return false;
            return q === '' || t.toLowerCase().indexOf(q) !== -1;
        }).slice(0, 8);

        if (matches.length === 0) return;

        suggestionsBox = document.createElement('div');
        suggestionsBox.className = 'tag-suggestions';

        matches.forEach(function (tag) {
            var item = document.createElement('div');
            item.className = 'tag-suggestion-item';
            item.textContent = tag;
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                addTag(tag);
                textInput.focus();
            });
            suggestionsBox.appendChild(item);
        });

        container.appendChild(suggestionsBox);
    }

    textInput.addEventListener('input', function () {
        showSuggestions(textInput.value);
    });

    textInput.addEventListener('focus', function () {
        showSuggestions(textInput.value);
    });

    textInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTag(textInput.value);
        } else if (e.key === 'Backspace' && textInput.value === '' && tags.length > 0) {
            tags.pop();
            renderChips();
            sync();
        } else if (e.key === 'Escape') {
            hideSuggestions();
        }
    });

    document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
            hideSuggestions();
        }
    });

    renderChips();
}
