#!/usr/bin/env bash
#
# SONDE — la vraie course sur `client_uuid`.
#
# CE QUE LA SONDE PRÉCÉDENTE NE POUVAIT PAS VOIR
# ===============================================
# Deux onglets du même navigateur partagent UNE session PHP, et le
# gestionnaire de session par fichiers pose un verrou exclusif pour
# toute la durée de la requête. Les deux envois se suivaient donc,
# quel que soit le nombre de processus du serveur.
#
# Cette sérialisation est réelle en production — mais elle est
# INCIDENTE : elle tient au gestionnaire de session, pas au code de la
# synchronisation. Le jour où les sessions passent en base ou en
# mémoire partagée sans verrou, elle disparaît sans prévenir.
#
#   > Une correction qui repose sur une propriété qu'on n'a pas
#   > choisie n'est pas une correction, c'est un sursis.
#
# On ouvre donc DEUX sessions distinctes du même compte pour obtenir
# une vraie course, et on regarde ce que fait le garde d'idempotence :
# SELECT puis INSERT, avec un index UNIQUE derrière.
#
set -u

BASE="${SCHOOL_BASE:-http://127.0.0.1:8099}"
MDP="${1:-Demo2026Ecole}"
UUID="$(python3 -c 'import uuid;print(uuid.uuid4())')"

WORKERS=$(pgrep -fc 'php -S' || echo 0)
[ "$WORKERS" -lt 3 ] && { echo "  ✗ serveur mono-processus"; exit 2; }

echo
echo "  DEUX SESSIONS DISTINCTES DU MÊME COMPTE"

ouvrir() { # $1 = fichier cookie → imprime le jeton CSRF
  local jar="$1" t
  t=$(curl -s -b "$jar" -c "$jar" "$BASE/login" \
      | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"//')
  curl -s -b "$jar" -c "$jar" -o /dev/null \
    -d "identifier=enseignant.demo&password=$MDP&_token=$t" "$BASE/login"
  curl -s -b "$jar" -c "$jar" "$BASE/presences/classe/1" \
    | grep -o 'name="csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"//'
}

J1=$(mktemp); J2=$(mktemp)
T1=$(ouvrir "$J1"); T2=$(ouvrir "$J2")

[ -n "$T1" ] && [ -n "$T2" ] && echo "    ✓ deux sessions ouvertes" \
  || { echo "    ✗ échec"; exit 1; }

# UN SEUL appareil, partagé par les deux sessions : c'est bien la même
# file locale qui est vidée deux fois.
DU="$(python3 -c 'import uuid;print(uuid.uuid4())')"
DEV=$(curl -s -b "$J1" -H "Content-Type: application/json" -H "X-CSRF-Token: $T1" \
  -d "{\"device_uuid\":\"$DU\",\"label\":\"course\"}" "$BASE/sync/appareil" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["device_id"])')

echo "    ✓ appareil partagé n° $DEV"

VU=$(mysql --socket=/var/run/mysqld8/mysql8.sock -uroot school_saas -sN -e \
  "SELECT IFNULL(MAX(updated_at),'') FROM attendance_sessions WHERE classroom_id=1 AND session_date=CURDATE() AND slot='day';")
INSCR=$(mysql --socket=/var/run/mysqld8/mysql8.sock -uroot school_saas -sN -e \
  "SELECT id FROM enrollments WHERE classroom_id=1 LIMIT 1;")

CORPS=$(cat <<JSON
{"device_id":$DEV,"operations":[{"client_uuid":"$UUID","entity_type":"attendance_session",
"operation":"update","client_version":1,"client_time":"$(date '+%Y-%m-%d %H:%M:%S')",
"payload":{"classroom_id":1,"date":"$(date +%F)","slot":"day","seen_updated_at":"$VU",
"entries":{"$INSCR":{"status":"present","minutes_late":null}}}}]}
JSON
)

echo
echo "  COURSE SUR $UUID"

envoi() { # $1 = jar, $2 = jeton, $3 = numéro
  curl -s -o "/tmp/course_$3.json" -w "%{http_code}" -b "$1" \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $2" \
    -d "$CORPS" "$BASE/sync/envoyer" > "/tmp/course_code_$3.txt"
}

envoi "$J1" "$T1" 1 &
envoi "$J2" "$T2" 2 &
wait

ECHEC=0
for i in 1 2; do
  CODE=$(cat "/tmp/course_code_$i.txt")
  echo "    session $i → HTTP $CODE"
  echo "      $(head -c 220 "/tmp/course_$i.json")"
  [ "$CODE" = "200" ] || ECHEC=1
done

echo
echo "  ÉTAT DE LA BASE"
mysql --socket=/var/run/mysqld8/mysql8.sock -uroot school_saas -e \
  "SELECT COUNT(*) AS lignes_pour_cet_uuid FROM sync_queue WHERE client_uuid='$UUID';"

rm -f "$J1" "$J2" /tmp/course_*.json /tmp/course_code_*.txt
echo
[ "$ECHEC" = "0" ] && echo "  → aucune requête n'a rompu" || echo "  ⚠ UNE REQUÊTE A ROMPU"
echo
exit $ECHEC
