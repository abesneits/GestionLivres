/**
 * Saisie du champ « Auteur » : suggestions d'auteurs existants + aide au format.
 * Usage : initAuteurInput(inputEl, tousLesNoms)
 *  - inputEl : le champ texte (« Prénom Nom », plusieurs auteurs séparés par une virgule)
 *  - tousLesNoms : tableau des noms d'auteurs déjà présents dans la collection
 * Les suggestions portent sur le nom en cours de saisie (entre deux virgules). Le message
 * d'aide (élément .auteur-aide placé après le champ) signale la saisie « Nom, Prénom »,
 * qui créerait deux auteurs au lieu d'un.
 */
function initAuteurInput(input, tousLesNoms) {
    if (!input) {
        return;
    }
    tousLesNoms = tousLesNoms || [];

    var conteneur = input.parentElement;
    conteneur.classList.add('auteur-champ');
    var aide = conteneur.querySelector('.auteur-aide');
    var texteAide = aide ? aide.textContent : '';

    var boite = null;
    var actif = -1;
    var propositions = [];

    function normaliser(s) {
        return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    var connus = {};
    tousLesNoms.forEach(function (nom) { connus[normaliser(nom)] = true; });

    // Borne du nom en cours de saisie : entre la virgule précédente et la virgule suivante
    function jetonCourant() {
        var texte = input.value;
        var curseur = input.selectionStart === null ? texte.length : input.selectionStart;
        var debut = texte.lastIndexOf(',', curseur - 1) + 1;
        var fin = texte.indexOf(',', curseur);
        if (fin === -1) { fin = texte.length; }
        return { debut: debut, fin: fin, texte: texte.slice(debut, fin).trim() };
    }

    function fermer() {
        if (boite) {
            boite.remove();
            boite = null;
        }
        actif = -1;
        propositions = [];
        input.setAttribute('aria-expanded', 'false');
    }

    function choisir(nom) {
        var jeton = jetonCourant();
        var avant = input.value.slice(0, jeton.debut).replace(/\s+$/, '');
        var apres = input.value.slice(jeton.fin);
        // « Nom1, » + nom choisi (le début de la saisie se termine toujours par une virgule)
        var debutNom = avant === '' ? '' : avant + ' ';
        input.value = debutNom + nom + apres;
        var position = (debutNom + nom).length;
        input.setSelectionRange(position, position);
        fermer();
        verifierFormat();
        input.focus();
    }

    function surligner() {
        if (!boite) { return; }
        Array.prototype.forEach.call(boite.children, function (el, i) {
            el.classList.toggle('actif', i === actif);
            el.setAttribute('aria-selected', i === actif ? 'true' : 'false');
        });
    }

    function afficher() {
        var requete = normaliser(jetonCourant().texte);
        fermer();
        if (requete.length < 1) { return; }

        // Noms déjà saisis dans ce champ : inutile de les reproposer
        var dejaSaisis = {};
        var jeton = jetonCourant();
        (input.value.slice(0, jeton.debut) + ',' + input.value.slice(jeton.fin)).split(',').forEach(function (n) {
            if (n.trim() !== '') { dejaSaisis[normaliser(n)] = true; }
        });

        propositions = tousLesNoms.filter(function (nom) {
            var n = normaliser(nom);
            return n.indexOf(requete) !== -1 && n !== requete && !dejaSaisis[n];
        }).sort(function (a, b) {
            // Les noms qui commencent par la saisie (ou dont un mot commence par elle) d'abord
            var rang = function (nom) {
                var n = normaliser(nom);
                if (n.indexOf(requete) === 0) { return 0; }
                if (n.indexOf(' ' + requete) !== -1) { return 1; }
                return 2;
            };
            return rang(a) - rang(b) || a.localeCompare(b, 'fr');
        }).slice(0, 8);

        if (!propositions.length) { return; }

        boite = document.createElement('div');
        boite.className = 'tag-suggestions';
        boite.setAttribute('role', 'listbox');
        propositions.forEach(function (nom, i) {
            var item = document.createElement('div');
            item.className = 'tag-suggestion-item';
            item.setAttribute('role', 'option');
            item.textContent = nom;
            // mousedown (et non click) : le choix doit se faire avant que le champ perde le focus
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                choisir(nom);
            });
            item.addEventListener('mousemove', function () { actif = i; surligner(); });
            boite.appendChild(item);
        });
        conteneur.insertBefore(boite, input.nextSibling); // juste sous le champ, avant le texte d'aide
        input.setAttribute('aria-expanded', 'true');
    }

    // « Hugo, Victor » : deux mots seuls séparés par une virgule, inconnus de la collection,
    // ressemblent à un « Nom, Prénom » (un seul auteur) et non à deux auteurs
    function verifierFormat() {
        if (!aide) { return; }
        var noms = input.value.split(',').map(function (n) { return n.trim(); }).filter(function (n) { return n !== ''; });
        var suspect = noms.length === 2
            && noms.every(function (n) { return n.indexOf(' ') === -1; })
            && !(connus[normaliser(noms[0])] && connus[normaliser(noms[1])]);

        aide.classList.toggle('attention', suspect);
        aide.textContent = suspect
            ? 'Attention : la virgule sépare deux auteurs (« ' + noms[0] + ' » et « ' + noms[1] + ' »). Pour un seul auteur, écrivez « Prénom Nom » sans virgule.'
            : texteAide;
    }

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');

    input.addEventListener('input', function () { afficher(); verifierFormat(); });
    input.addEventListener('click', afficher);
    input.addEventListener('blur', function () { fermer(); verifierFormat(); });
    input.addEventListener('keydown', function (e) {
        if (!boite) { return; }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            actif = (actif + 1) % propositions.length;
            surligner();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            actif = actif <= 0 ? propositions.length - 1 : actif - 1;
            surligner();
        } else if ((e.key === 'Enter' || e.key === 'Tab') && actif >= 0) {
            // Entrée ne valide le formulaire que si aucune suggestion n'est sélectionnée
            e.preventDefault();
            choisir(propositions[actif]);
        } else if (e.key === 'Escape') {
            fermer();
        }
    });

    verifierFormat();
}
