#!/usr/bin/env bash
#
# RECETTE — le produit s'installe-t-il DEPUIS ZÉRO ?
#
# POURQUOI CETTE ÉPREUVE EXISTE
# ==============================
# Mesuré le 21/09/2026 sur une base vierge : la commande documentée
# `php database/migrate.php` sautait les deux seeds, puis la migration
# 001b échouait sur « Column 'cycle_id' cannot be null ». Le produit
# passait 921 tests, et pourtant AUCUNE école neuve n'aurait pu être
# installée. L'erreur ne nommait même pas la vraie cause.
#
#   > Un produit qui ne s'installe pas depuis zéro n'a jamais été
#   > installé, il a seulement été migré.
#
# Une suite de tests ne peut pas attraper cela : elle s'exécute sur la
# base déjà construite. Seule une base RÉELLEMENT VIDE le montre.
#
# CE SCRIPT DÉTRUIT LA BASE CONFIGURÉE.
# Il demande donc confirmation, et ne s'exécute jamais tout seul. À
# jouer avant chaque livraison, sur un poste de développement.
#
# Usage : bash tests/installation_zero.sh [--oui]
#
set -u

cd "$(dirname "$0")/.." || exit 1

BASE=$(php -r 'define("BASE_PATH",getcwd());define("APP_PATH",getcwd()."/app");
require "app/core/helpers.php"; echo config("database.name");')
SOCKET="${MYSQL_SOCKET:-/var/run/mysqld8/mysql8.sock}"
MYSQL="mysql --socket=$SOCKET -uroot"

echo
echo "  INSTALLATION DEPUIS ZÉRO"
echo "  ─────────────────────────────────────────────"
echo
echo "  ⚠  Ce script DÉTRUIT et reconstruit la base « $BASE »."
echo "     Toutes ses données seront perdues."
echo

if [ "${1:-}" != "--oui" ]; then
  printf "     Tapez « detruire » pour confirmer : "
  read -r reponse
  [ "$reponse" = "detruire" ] || { echo "  ✗ Annulé."; echo; exit 1; }
fi

echo
$MYSQL -e "DROP DATABASE IF EXISTS \`$BASE\`;
           CREATE DATABASE \`$BASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || exit 1

echo "  ✓ base vidée"

# LA COMMANDE DOCUMENTÉE, telle quelle. C'est elle qu'on éprouve — pas
# une variante avec des drapeaux que personne n'écrira en production.
if php database/migrate.php > /tmp/install_zero.log 2>&1; then
  echo "  ✓ php database/migrate.php — terminé sans erreur"
else
  echo "  ✗ php database/migrate.php a ÉCHOUÉ :"
  tail -8 /tmp/install_zero.log | sed 's/^/      /'
  exit 1
fi

echo
echo "  LE RÉFÉRENTIEL EST-IL EN PLACE ?"
echo

# Sans ces lignes, le produit n'a pas un seul rôle : aucune école ne
# peut être créée, aucun compte ne peut se connecter.
verifier() { # $1 = table, $2 = minimum attendu
  local n
  n=$($MYSQL "$BASE" -sN -e "SELECT COUNT(*) FROM $1;" 2>/dev/null)

  if [ -z "$n" ]; then
    echo "    ✗ $1 — table absente"
    return 1
  fi

  if [ "$n" -lt "$2" ]; then
    echo "    ✗ $1 — $n ligne(s), au moins $2 attendue(s)"
    return 1
  fi

  printf "    ✓ %-22s %s ligne(s)\n" "$1" "$n"
}

ECHEC=0
verifier education_cycles     4  || ECHEC=1
verifier education_levels    12  || ECHEC=1
verifier reference_sections   5  || ECHEC=1
verifier roles                9  || ECHEC=1
verifier permissions         70  || ECHEC=1
verifier role_permissions   100  || ECHEC=1
verifier plans                4  || ECHEC=1

# Une école neuve doit pouvoir exister : c'est la finalité de tout ceci.
echo
echo "  UNE ÉCOLE NEUVE PEUT-ELLE ÊTRE CRÉÉE ?"
echo

# `bootstrap.php` définit lui-même BASE_PATH : le redéfinir ici
# produirait un avertissement qui polluerait la sortie lue plus bas.
CREATION=$(php -r '
require "app/bootstrap.php";
try {
    $id = db_insert("schools", [
        "uuid" => str_uuid(), "code" => "ZERO", "slug" => "zero",
        "name" => "École de vérification", "status" => "active",
    ], true);
    db_query("INSERT INTO school_cycles (school_id, cycle_id, is_active)
              SELECT :s, id, 1 FROM education_cycles", ["s" => $id]);
    $n = (int) db_value("SELECT COUNT(*) FROM school_cycles WHERE school_id = :s",
        ["s" => $id], true);
    db_query("DELETE FROM school_cycles WHERE school_id = :s", ["s" => $id], true);
    db_query("DELETE FROM schools WHERE id = :s", ["s" => $id], true);
    echo $n;
} catch (Throwable $e) { echo "ERREUR: " . $e->getMessage(); }
' 2>&1)

if [ "$CREATION" -ge 1 ] 2>/dev/null; then
  echo "    ✓ école créée et rattachée à $CREATION cycle(s), puis retirée"
else
  echo "    ✗ $CREATION"
  ECHEC=1
fi

echo
if [ "$ECHEC" = "0" ]; then
  echo "  → le produit s'installe depuis zéro."
  echo
  echo "  La base est VIDE de données d'école. Pour une démonstration :"
  echo "      php database/seed_demo.php"
else
  echo "  ⚠ L'INSTALLATION DEPUIS ZÉRO EST CASSÉE."
fi
echo

exit $ECHEC
