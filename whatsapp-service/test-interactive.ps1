# Test-Scripts fuer interaktive WhatsApp-Nachrichten
# Ersetze $PhoneNumber mit deiner Testnummer (z.B. 4915123456789)

param(
    [string]$PhoneNumber = "4915123456789",
    [string]$BaseUrl = "http://127.0.0.1:3000"
)

Write-Host "=== Test 1: Quick Reply Buttons (Legacy) ===" -ForegroundColor Cyan
$body1 = @{
    phone = $PhoneNumber
    type = "buttons"
    text = "Wie koennen wir Ihnen helfen?"
    footer = "WhatsApp Support Bot"
    buttons = @(
        @{ id = "btn_tickets"; text = "Meine Tickets" }
        @{ id = "btn_new"; text = "Neues Ticket" }
        @{ id = "btn_close"; text = "Ticket schliessen" }
    )
} | ConvertTo-Json -Depth 4

try {
    Invoke-RestMethod -Uri "$BaseUrl/sendInteractive" -Method Post -Body $body1 -ContentType "application/json" | ConvertTo-Json
} catch {
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

Write-Host "=== Test 2: List Message (Single Select) ===" -ForegroundColor Cyan
$body2 = @{
    phone = $PhoneNumber
    type = "list"
    text = "Waehlen Sie eine Aktion aus der Liste:"
    footer = "WhatsApp Support Bot"
    title = "Hauptmenue"
    buttons = @(
        @{ id = "list_tickets"; text = "Offene Tickets anzeigen"; description = "Zeigt alle Ihre offenen Tickets" }
        @{ id = "list_new"; text = "Neues Ticket erstellen"; description = "Erstellt ein neues Support-Ticket" }
        @{ id = "list_close"; text = "Aktuelles Ticket schliessen"; description = "Schliesst Ihr aktives Ticket" }
        @{ id = "list_help"; text = "Hilfe"; description = "Zeigt verfuegbare Befehle" }
    )
} | ConvertTo-Json -Depth 4

try {
    Invoke-RestMethod -Uri "$BaseUrl/sendInteractive" -Method Post -Body $body2 -ContentType "application/json" | ConvertTo-Json
} catch {
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

Write-Host "=== Test 3: Native Flow Interactive (Modern Buttons) ===" -ForegroundColor Cyan
$body3 = @{
    phone = $PhoneNumber
    type = "interactive"
    text = "Willkommen beim Support! Was moechten Sie tun?"
    footer = "Powered by osTicket"
    title = "Support Bot"
    buttons = @(
        @{ type = "reply"; id = "qr_tickets"; text = "Meine Tickets" }
        @{ type = "reply"; id = "qr_new"; text = "Neues Ticket" }
        @{ type = "url"; text = "Hilfe-Portal"; url = "https://example.com/help" }
        @{ type = "copy"; text = "Ticket-Nr kopieren"; code = "TICKET-123456" }
    )
} | ConvertTo-Json -Depth 4

try {
    Invoke-RestMethod -Uri "$BaseUrl/sendInteractive" -Method Post -Body $body3 -ContentType "application/json" | ConvertTo-Json
} catch {
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ""

Write-Host "=== Alle Tests abgeschlossen ===" -ForegroundColor Green
