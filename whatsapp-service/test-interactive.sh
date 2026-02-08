#!/bin/bash
# Test-Scripts fuer interaktive WhatsApp-Nachrichten
# Ersetze PHONE_NUMBER mit deiner Testnummer (z.B. 4915123456789)

PHONE_NUMBER="${1:-4915123456789}"
BASE_URL="${2:-http://127.0.0.1:3000}"

echo "=== Test 1: Quick Reply Buttons (Legacy) ==="
curl -X POST "$BASE_URL/sendInteractive" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "'"$PHONE_NUMBER"'",
    "type": "buttons",
    "text": "Wie koennen wir Ihnen helfen?",
    "footer": "WhatsApp Support Bot",
    "buttons": [
      { "id": "btn_tickets", "text": "Meine Tickets" },
      { "id": "btn_new", "text": "Neues Ticket" },
      { "id": "btn_close", "text": "Ticket schliessen" }
    ]
  }'
echo -e "\n"

echo "=== Test 2: List Message (Single Select) ==="
curl -X POST "$BASE_URL/sendInteractive" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "'"$PHONE_NUMBER"'",
    "type": "list",
    "text": "Waehlen Sie eine Aktion aus der Liste:",
    "footer": "WhatsApp Support Bot",
    "title": "Hauptmenue",
    "buttons": [
      { "id": "list_tickets", "text": "Offene Tickets anzeigen", "description": "Zeigt alle Ihre offenen Tickets" },
      { "id": "list_new", "text": "Neues Ticket erstellen", "description": "Erstellt ein neues Support-Ticket" },
      { "id": "list_close", "text": "Aktuelles Ticket schliessen", "description": "Schliesst Ihr aktives Ticket" },
      { "id": "list_help", "text": "Hilfe", "description": "Zeigt verfuegbare Befehle" }
    ]
  }'
echo -e "\n"

echo "=== Test 3: Native Flow Interactive (Modern Buttons) ==="
curl -X POST "$BASE_URL/sendInteractive" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "'"$PHONE_NUMBER"'",
    "type": "interactive",
    "text": "Willkommen beim Support! Was moechten Sie tun?",
    "footer": "Powered by osTicket",
    "title": "Support Bot",
    "buttons": [
      { "type": "reply", "id": "qr_tickets", "text": "Meine Tickets" },
      { "type": "reply", "id": "qr_new", "text": "Neues Ticket" },
      { "type": "url", "text": "Hilfe-Portal", "url": "https://example.com/help" },
      { "type": "copy", "text": "Ticket-Nr kopieren", "code": "TICKET-123456" }
    ]
  }'
echo -e "\n"

echo "=== Alle Tests abgeschlossen ==="
