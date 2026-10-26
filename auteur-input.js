/**
 * Saisie du champ « Auteur » : suggestions d'auteurs existants et de rôles, aide au format.
 * Usage : initAuteurInput(inputEl, tousLesNoms, roles)
 *  - inputEl : le champ texte, au format « Prénom Nom, Prénom Nom (Rôle) »
 *  - tousLesNoms : tableau des noms d'auteurs déjà présents dans la collection
 *  - roles : tableau des rôles possibles (ex. « Scénariste ») ; proposés après une parenthèse ouvrante
 * Une virgule sépare deux auteurs, sauf entre parenthèses (« Nom (Scénariste, Dessinateur) »).
 * Les suggestions portent sur l'auteur en cours de saisie. Le message d'aide (élément .auteur-aide
 * placé après le champ) signale la saisie « Nom, Prénom », qui créerait deux auteurs au lieu d'un.
 */
function initAuteurInput(input, tousLesNoms, roles) {
    if (!input) {
        return;
    }
    tousLesNoms = tousLesNoms || [];
    roles = roles || [];

    var conteneur = input.parentElement;
    conteneur.classList.add('auteur-champ');
    var aide = conteneur.querySelector('.auteur-aide');
    var texteAide = aide ? aide.textContent : '';

    var boite = null;
    var actif = -1;
    var propositions = [];
    var modeRole = false;

    function normaliser(s) {
        return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    var connus = {};
    tousLesNoms.forEach(function (nom) { connus[normaliser(nom)] = true; });

    // Découpe le texte sur les virgules situées hors des parenthèses ; retourne les bornes de chaque auteur
    function auteurs(texte) {
        var bornes = [];
        var debut = 0;
        var profondeur = 0;
        for (var i = 0; i < texte.length; i++) {
            var c = texte.charAt(i);
            if (c === '(') { profondeur++; }
            else if (c === ')' && profondeur > 0) { profondeur--; }
            else if (c === ',' && profondeur === 0) {
                bornes.push({ debut: debut, fin: i });
                debut = i + 1;
            }
        }
        bornes.push({ debut: debut, fin: texte.length });
        return bornes;
    }

    // Retire la parenthèse de rôle finale : « Hugo (Scénariste) » -> « Hugo »
    function sansRole(nom) {
        return nom.replace(/\s*\([^()]*\)\s*$/, '').trim();
    }

    // Auteur sous le curseur, et état de la saisie dans cet auteur
    function contexte() {
        var texte = input.value;
        var curseur = input.selectionStart === null ? texte.length : input.selectionStart;
        var borne = auteurs(texte).filter(function (b) { return curseur >= b.debut && curseur <= b.fin; })[0] || { debut: 0, fin: texte.length };
        var jeton = texte.slice(borne.debut, borne.fin);
        var posCurseur = curseur - borne.debut;

        var ouvrante = jeton.lastIndexOf('(', posCurseur - 1);
        var fermante = jeton.lastIndexOf(')', posCurseur - 1);
        var dansParentheses = ouvrante !== -1 && ouvrante > fermante;

        var info = { debut: borne.debut, fin: borne.fin, jeton: jeton, pos: posCurseur, dansParentheses: dansParentheses };
        if (dansParentheses) {
            // Rôle en cours : après la dernière ouvrante, virgule, barre oblique ou point-virgule
            var depart = Math.max(ouvrante, jeton.lastIndexOf(',', posCurseur - 1), jeton.lastIndexOf('/', posCurseur - 1), jeton.lastIndexOf(';', posCurseur - 1));
            info.departFragment = depart + 1;
            info.fragment = jeton.slice(depart + 1, posCurseur).trim();
            info.dejaChoisis = jeton.slice(ouvrante + 1).replace(/\).*$/, '').split(/[,\/;]/).map(normaliser);
        } else {
            // Nom en cours : jusqu'à la parenthèse éventuelle
            var parenthese = jeton.indexOf('(');
            info.finNom = parenthese === -1 ? jeton.length : parenthese;
            info.fragment = jeton.slice(0, Math.min(posCurseur, info.finNom)).trim();
        }
        return info;
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

    function placerCurseur(position) {
        input.setSelectionRange(position, position);
        fermer();
        verifierFormat();
        input.focus();
    }

    function choisirNom(nom) {
        var ctx = contexte();
        var texte = input.value;
        var avant = texte.slice(0, ctx.debut).replace(/\s+$/, '');
        var suite = ctx.jeton.slice(ctx.finNom).replace(/^\s+/, '');   // « (Rôle) » éventuel, conservé
        var debutNom = avant === '' ? '' : avant + ' ';                 // le début se termine toujours par une virgule
        var nouveauJeton = nom + (suite ? ' ' + suite : '');
        input.value = debutNom + nouveauJeton + texte.slice(ctx.fin);
        placerCurseur((debutNom + nom).length);
    }

    function choisirRole(role) {
        var ctx = contexte();
        var texte = input.value;
        var debutJeton = ctx.debut;
        var avantFragment = texte.slice(0, debutJeton + ctx.departFragment);
        var espace = /[,\/;]$/.test(avantFragment) ? ' ' : '';
        var apresCurseur = texte.slice(debutJeton + ctx.pos);
        // La parenthèse est déjà fermée si une « ) » suit le curseur avant toute autre parenthèse
        var reste = /^[^()]*\)/.test(apresCurseur) ? apresCurseur : ')' + apresCurseur;
        input.value = avantFragment + espace + role + reste;
        var apresParenthese = (avantFragment + espace + role).length + 1;
        placerCurseur(Math.min(apresParenthese, input.value.length));
    }

    function surligner() {
        if (!boite) { return; }
        Array.prototype.forEach.call(boite.children, function (el, i) {
            el.classList.toggle('actif', i === actif);
            el.setAttribute('aria-selected', i === actif ? 'true' : 'false');
        });
    }

    function afficher() {
        var ctx = contexte();
        fermer();

        if (ctx.dansParentheses) {
            // Rôles : tous dès l'ouverture de la parenthèse, puis filtrés par ce qui est tapé
            var requeteRole = normaliser(ctx.fragment);
            modeRole = true;
            propositions = roles.filter(function (r) {
                var n = normaliser(r);
                return n.indexOf(requeteRole) === 0 && ctx.dejaChoisis.indexOf(n) === -1;
            }).slice(0, 8);
        } else {
            modeRole = false;
            var requete = normaliser(ctx.fragment);
            if (requete.length < 1) { return; }

            // Auteurs déjà saisis dans ce champ : inutile de les reproposer
            var dejaSaisis = {};
            auteurs(input.value).forEach(function (b) {
                if (b.debut === ctx.debut) { return; }
                var n = normaliser(sansRole(input.value.slice(b.debut, b.fin)));
                if (n !== '') { dejaSaisis[n] = true; }
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
        }

        if (!propositions.length) { return; }

        boite = document.createElement('div');
        boite.className = 'tag-suggestions';
        boite.setAttribute('role', 'listbox');
        propositions.forEach(function (texte, i) {
            var item = document.createElement('div');
            item.className = 'tag-suggestion-item';
            item.setAttribute('role', 'option');
            item.textContent = texte;
            // mousedown (et non click) : le choix doit se faire avant que le champ perde le focus
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                modeRole ? choisirRole(texte) : choisirNom(texte);
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
        var noms = auteurs(input.value)
            .map(function (b) { return sansRole(input.value.slice(b.debut, b.fin)); })
            .filter(function (n) { return n !== ''; });
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
            modeRole ? choisirRole(propositions[actif]) : choisirNom(propositions[actif]);
        } else if (e.key === 'Escape') {
            fermer();
        }
    });

    verifierFormat();
}
