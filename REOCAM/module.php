<?php

declare(strict_types=1);

class Reolink extends IPSModule
{
    /* =========================
     * ====== LIFECYCLE ========
     * ========================= */

    public function Create()
    {
        parent::Create();

        // --- Basis
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StreamType", "sub"); // main|sub
        $this->RegisterPropertyBoolean("InstanceStatus", true);

        // --- Sichtbarkeit/Elemente
        $this->RegisterPropertyBoolean("ShowMoveVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);
        $this->RegisterPropertyBoolean("ShowArchives", true);
        $this->RegisterPropertyBoolean("ShowTestElements", false);
        $this->RegisterPropertyBoolean("ShowVisitorElements", false);

        // --- Polling (optional)
        $this->RegisterPropertyBoolean("EnablePolling", false);
        $this->RegisterPropertyInteger("PollingInterval", 2);

        // --- Feature-Schalter
        $this->RegisterPropertyBoolean("EnableApiWhiteLed", true);
        $this->RegisterPropertyBoolean("EnableApiEmail", true);
        $this->RegisterPropertyBoolean("EnableApiPTZ", false);

        // --- Archiv
        $this->RegisterPropertyInteger("MaxArchiveImages", 20);

        // --- Attribute
        $this->RegisterAttributeBoolean("ApiInitialized", false);
        $this->RegisterAttributeBoolean("TokenRefreshing", false);
        $this->RegisterAttributeInteger("ApiTokenExpiresAt", 0);
        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString("ApiToken", "");
        $this->RegisterAttributeString("EmailApiVersion", "");
        $this->RegisterAttributeString("PtzStyle", "");

        // --- Timer (nutzen die automatisch generierten REOCAM_* Wrapper!)
        $this->RegisterTimer("Person_Reset",    0, 'REOCAM_ResetMoveTimer($_IPS["TARGET"], "Person");');
        $this->RegisterTimer("Tier_Reset",      0, 'REOCAM_ResetMoveTimer($_IPS["TARGET"], "Tier");');
        $this->RegisterTimer("Fahrzeug_Reset",  0, 'REOCAM_ResetMoveTimer($_IPS["TARGET"], "Fahrzeug");');
        $this->RegisterTimer("Bewegung_Reset",  0, 'REOCAM_ResetMoveTimer($_IPS["TARGET"], "Bewegung");');
        $this->RegisterTimer("Test_Reset",      0, 'REOCAM_ResetMoveTimer($_IPS["TARGET"], "Test");');
        $this->RegisterTimer("Besucher_Reset",  0, 'REOCAM_ResetMoveTimer($_IPS["TARGET"], "Besucher");');

        $this->RegisterTimer("PollingTimer",       0, 'REOCAM_Polling($_IPS["TARGET"]);');
        $this->RegisterTimer("ApiRequestTimer",    0, 'REOCAM_ExecuteApiRequests($_IPS["TARGET"]);');
        $this->RegisterTimer("TokenRenewalTimer",  0, 'REOCAM_GetToken($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $enabled = $this->ReadPropertyBoolean("InstanceStatus");
        if (!$enabled) {
            $this->SetStatus(104); // IS_INACTIVE
            foreach ([
                "Person_Reset","Tier_Reset","Fahrzeug_Reset","Bewegung_Reset",
                "Test_Reset","Besucher_Reset","PollingTimer","ApiRequestTimer","TokenRenewalTimer"
            ] as $t) {
                $this->SetTimerInterval($t, 0);
            }
            // Token clean
            $this->WriteAttributeString("ApiToken", "");
            $this->WriteAttributeInteger("ApiTokenExpiresAt", 0);
            $this->WriteAttributeBoolean("TokenRefreshing", false);
            $this->logInfo('CORE', 'Instanz deaktiviert – Timer gestoppt, Token geleert.');
            return;
        }

        $this->SetStatus(102); // IS_ACTIVE

        // Hook sicherstellen
        $hookPath = $this->ReadAttributeString("CurrentHook");
        if ($hookPath === "") {
            $hookPath = $this->RegisterHook();
        }
        $this->dbg('WEBHOOK', 'Init', ['relative'=>$hookPath, 'full'=>$this->buildFullWebhookUrlSafe()]);

        // Stream
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");

        // UI Elemente
        if ($this->ReadPropertyBoolean("ShowMoveVariables")) { $this->CreateMoveVariables(); } else { $this->RemoveMoveVariables(); }
        if (!$this->ReadPropertyBoolean("ShowSnapshots")) { $this->RemoveSnapshots(); }
        if ($this->ReadPropertyBoolean("ShowArchives")) { $this->CreateOrUpdateArchives(); } else { $this->RemoveArchives(); }
        if ($this->ReadPropertyBoolean("ShowTestElements")) { $this->CreateTestElements(); } else { $this->RemoveTestElements(); }
        if ($this->ReadPropertyBoolean("ShowVisitorElements")) { $this->CreateVisitorElements(); } else { $this->RemoveVisitorElements(); }

        // Polling
        if ($this->ReadPropertyBoolean("EnablePolling")) {
            $this->SetTimerInterval("PollingTimer", max(2, $this->ReadPropertyInteger("PollingInterval")) * 1000);
        } else {
            $this->SetTimerInterval("PollingTimer", 0);
        }

        // API Feature-Gruppen
        $enableWhiteLed = $this->ReadPropertyBoolean("EnableApiWhiteLed");
        $enableEmail    = $this->ReadPropertyBoolean("EnableApiEmail");
        $enablePTZ      = $this->ReadPropertyBoolean("EnableApiPTZ");
        if ($enableWhiteLed || $enableEmail || $enablePTZ) {
            $this->SetTimerInterval("ApiRequestTimer", 10 * 1000);
            $this->SetTimerInterval("TokenRenewalTimer", 0);

            $this->WriteAttributeBoolean("ApiInitialized", false);
            $this->CreateApiVariables();
            $this->GetToken();
            $this->ExecuteApiRequests();
        } else {
            $this->SetTimerInterval("ApiRequestTimer", 0);
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            $this->RemoveApiVariables();
        }

        $this->logInfo('CORE', 'ApplyChanges abgeschlossen.');
    }

    /* =========================
     * ====== FORM / HOOK ======
     * ========================= */

    public function GetConfigurationForm()
    {
        // Nur FULL Hook (kein relativer)
        $full = $this->buildFullWebhookUrlSafe();

        $form = [
            "elements" => [
                [ "type"=>"Label", "name"=>"WebhookFull", "caption"=>"Webhook (voll): ".$full ],
                [ "type"=>"CheckBox", "name"=>"InstanceStatus", "caption"=>"Instanz aktivieren" ],
                [ "type"=>"ValidationTextBox", "name"=>"CameraIP", "caption"=>"Kamera IP" ],
                [ "type"=>"ValidationTextBox", "name"=>"Username", "caption"=>"Benutzername" ],
                [ "type"=>"PasswordTextBox", "name"=>"Password", "caption"=>"Passwort" ],
                [
                    "type"=>"Select", "name"=>"StreamType", "caption"=>"Stream-Typ",
                    "options"=>[
                        ["caption"=>"Mainstream","value"=>"main"],
                        ["caption"=>"Substream","value"=>"sub"]
                    ]
                ],
                [ "type"=>"CheckBox", "name"=>"EnablePolling", "caption"=>"Polling aktivieren (für Kameras ohne Webhook-Unterstützung)" ],
                [ "type"=>"NumberSpinner", "name"=>"PollingInterval", "caption"=>"Polling-Intervall", "suffix"=>"Sekunden", "minimum"=>2, "maximum"=>3600 ],

                [ "type"=>"CheckBox", "name"=>"ShowTestElements", "caption"=>"Test-Funktion Bewegungserkennung (Webhook)" ],
                [ "type"=>"CheckBox", "name"=>"ShowVisitorElements", "caption"=>"Besucher-Erkennung (Doorbell)" ],
                [ "type"=>"CheckBox", "name"=>"ShowMoveVariables", "caption"=>"Intelligente Bewegungserkennung" ],
                [ "type"=>"CheckBox", "name"=>"ShowSnapshots", "caption"=>"Schnappschüsse anzeigen" ],
                [ "type"=>"CheckBox", "name"=>"ShowArchives", "caption"=>"Bildarchive anzeigen" ],
                [ "type"=>"NumberSpinner", "name"=>"MaxArchiveImages", "caption"=>"Maximale Anzahl Archivbilder", "minimum"=>1, "suffix"=>"Bilder" ],

                [
                    "type"=>"ExpansionPanel", "caption"=>"API-Funktionen",
                    "items"=>[
                        [ "type"=>"CheckBox", "name"=>"EnableApiWhiteLed", "caption"=>"Spotlight steuern" ],
                        [ "type"=>"CheckBox", "name"=>"EnableApiEmail",    "caption"=>"E-Mail-Benachrichtigungen konfigurieren" ],
                        [ "type"=>"CheckBox", "name"=>"EnableApiPTZ",      "caption"=>"PTZ/Preset/Zoom HTML-Kachel" ]
                    ]
                ]
            ],
            "actions" => [],
            "status" => []
        ];

        return json_encode($form);
    }

    private function RegisterHook(): string
    {
        $hookBase = '/hook/reolink_';
        $hookPath = $this->ReadAttributeString("CurrentHook");
        if ($hookPath === "") {
            $hookPath = $hookBase . $this->InstanceID;
            $this->WriteAttributeString("CurrentHook", $hookPath);
        }

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'); // WebHook Control
        if (count($ids) === 0) {
            $this->dbg('WEBHOOK', 'Keine WebHook-Control-Instanz gefunden.');
            return $hookPath;
        }
        $hookInstanceID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);
        if (!is_array($hooks)) $hooks = [];

        foreach ($hooks as $hook) {
            if (($hook['Hook'] ?? '') === $hookPath && ($hook['TargetID'] ?? 0) === $this->InstanceID) {
                $this->dbg('WEBHOOK', "Bereits registriert", $hookPath);
                return $hookPath;
            }
        }
        $hooks[] = ['Hook' => $hookPath, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookInstanceID);

        $this->logInfo('WEBHOOK', 'registriert: '.$this->buildFullWebhookUrlSafe());
        return $hookPath;
    }

    public function ProcessHookData()
    {
        if (!$this->ReadPropertyBoolean("InstanceStatus") || $this->GetStatus() !== 102) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('HTTP/1.1 204 No Content');
            return;
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }

        $this->dbg('WEBHOOK', 'triggered');

        $ptz = null;
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['ptz'])) {
                $ptz = (string)$data['ptz'];
                if (isset($data['id']))   $_REQUEST['id']   = $data['id'];
                if (isset($data['name'])) $_REQUEST['name'] = $data['name'];
            }
        }
        if ($ptz === null) {
            if (isset($_POST['ptz'])) { $ptz = (string)$_POST['ptz']; }
            elseif (isset($_GET['ptz'])) { $ptz = (string)$_GET['ptz']; }
        }

        if ($ptz !== null) {
            $this->dbg('PTZ', 'Webhook-PTZ', ['ptz'=>$ptz, 'id'=>($_REQUEST['id']??''), 'name'=>($_REQUEST['name']??'')]);
            $ok = $this->HandlePtzCommand($ptz);
            header('Content-Type: text/plain; charset=utf-8');
            echo $ok ? "OK" : "ERROR";
            return;
        }

        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $this->ProcessAllData($data);
                header('Content-Type: text/plain; charset=utf-8');
                echo "OK";
                return;
            }
        }

        $this->dbg('WEBHOOK', 'No PTZ / no usable payload');
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR";
    }

    /* =========================
     * ===== SNAP/ARCHIVE ======
     * ========================= */

    private function ProcessAllData($data)
    {
        if (!isset($data['alarm']['type'])) return;

        switch ($data['alarm']['type']) {
            case "PEOPLE":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Person", 21);
                $this->SetMoveTimer("Person");
                break;
            case "ANIMAL":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Tier", 26);
                $this->SetMoveTimer("Tier");
                break;
            case "VEHICLE":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Fahrzeug", 31);
                $this->SetMoveTimer("Fahrzeug");
                break;
            case "MD":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Bewegung", 36);
                $this->SetMoveTimer("Bewegung");
                break;
            case "VISITOR":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Besucher", 41);
                $this->SetMoveTimer("Besucher");
                break;
            case "TEST":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Test", 46);
                if ($this->ReadPropertyBoolean("ShowTestElements")) $this->SetMoveTimer("Test");
                break;
        }
    }

    private function SetMoveTimer(string $ident)
    {
        $this->dbg('SNAPSHOT', "Setze '$ident' auf true");
        $this->SetValue($ident, true);
        $this->SetTimerInterval($ident . "_Reset", 5000);
    }

    public function ResetMoveTimer(string $ident)
    {
        $this->dbg('SNAPSHOT', "Reset '$ident' -> false");
        $this->SetValue($ident, false);
        $this->SetTimerInterval($ident . "_Reset", 0);
    }

    private function CreateMoveVariables()
    {
        $this->RegisterVariableBoolean("Person",   "Person",             "~Motion", 20);
        $this->RegisterVariableBoolean("Tier",     "Tier",               "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug",           "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);
        $this->RegisterVariableBoolean("Besucher", "Besucher",           "~Motion", 40);
        $this->RegisterVariableBoolean("Test",     "Test",               "~Motion", 45);
    }

    private function RemoveMoveVariables()
    {
        foreach (["Person","Tier","Fahrzeug","Bewegung","Besucher","Test"] as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false) $this->UnregisterVariable($ident);
        }
    }

    private function CreateSnapshotAtPosition(string $booleanIdent, int $position)
    {
        if (!$this->ReadPropertyBoolean("ShowTestElements") && $booleanIdent === "Test") return;
        if (!$this->ReadPropertyBoolean("ShowVisitorElements") && $booleanIdent === "Besucher") return;

        $snapshotIdent = "Snapshot_" . $booleanIdent;
        $mediaID = @$this->GetIDForIdent($snapshotIdent);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1); // 1=Bild
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $snapshotIdent);
            IPS_SetPosition($mediaID, $position);
            IPS_SetName($mediaID, "Snapshot von " . $booleanIdent);
            IPS_SetMediaCached($mediaID, false);
        }

        $snapshotUrl = $this->GetSnapshotURL();
        $fileName = $booleanIdent . "_" . $mediaID . ".jpg";
        $filePath = IPS_GetKernelDir() . "media/" . $fileName;

        $imageData = @file_get_contents($snapshotUrl);
        if ($imageData !== false) {
            IPS_SetMediaFile($mediaID, $filePath, false);
            IPS_SetMediaContent($mediaID, base64_encode($imageData));
            IPS_SendMediaEvent($mediaID);

            $this->dbg('SNAPSHOT', 'Snapshot OK', $this->maskSensitive($snapshotUrl));

            if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                $catID = $this->CreateOrGetArchiveCategory($booleanIdent);
                $this->CreateArchiveSnapshot($booleanIdent, $catID);
            }
        } else {
            $this->dbg('SNAPSHOT', 'Snapshot FEHLER', $this->maskSensitive($snapshotUrl));
        }
    }

    private function RemoveSnapshots()
    {
        foreach (["Snapshot_Person","Snapshot_Tier","Snapshot_Fahrzeug","Snapshot_Test","Snapshot_Besucher","Snapshot_Bewegung"] as $ident) {
            $mid = @$this->GetIDForIdent($ident);
            if ($mid) IPS_DeleteMedia($mid, true);
        }
    }

    private function CreateOrUpdateArchives()
    {
        foreach (["Person","Tier","Fahrzeug","Bewegung","Besucher","Test"] as $cat) {
            $this->CreateOrGetArchiveCategory($cat);
        }
    }

    private function CreateOrGetArchiveCategory(string $booleanIdent)
    {
        $archiveIdent = "Archive_" . $booleanIdent;
        $categoryID = @$this->GetIDForIdent($archiveIdent);
        if ($categoryID === false) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, $archiveIdent);
            IPS_SetName($categoryID, "Bildarchiv " . $booleanIdent);
            $pos = [ "Person"=>22, "Tier"=>27, "Fahrzeug"=>32, "Bewegung"=>37, "Besucher"=>42, "Test"=>47 ][$booleanIdent] ?? 99;
            IPS_SetPosition($categoryID, $pos);
        }
        return $categoryID;
    }

    private function CreateArchiveSnapshot(string $booleanIdent, int $categoryID)
    {
        $archiveIdent = "Archive_" . $booleanIdent . "_" . time();
        $mediaID = IPS_CreateMedia(1);
        IPS_SetParent($mediaID, $categoryID);
        IPS_SetIdent($mediaID, $archiveIdent);
        IPS_SetPosition($mediaID, -time());
        IPS_SetName($mediaID, $booleanIdent . " " . date("Y-m-d H:i:s"));
        IPS_SetMediaCached($mediaID, false);

        $snapshotUrl = $this->GetSnapshotURL();
        $archiveImagePath = IPS_GetKernelDir() . "media/" . $booleanIdent . "_" . $mediaID . ".jpg";
        $imageData = @file_get_contents($snapshotUrl);
        if ($imageData !== false) {
            IPS_SetMediaFile($mediaID, $archiveImagePath, false);
            IPS_SetMediaContent($mediaID, base64_encode($imageData));
            IPS_SendMediaEvent($mediaID);
            $this->PruneArchive($categoryID, $booleanIdent);
            $this->dbg('ARCHIVE', 'Archivbild OK', $this->maskSensitive($snapshotUrl));
        } else {
            $this->dbg('ARCHIVE', 'Archivbild FEHLER', $this->maskSensitive($snapshotUrl));
        }
    }

    private function PruneArchive(int $categoryID, string $booleanIdent)
    {
        $maxImages = $this->ReadPropertyInteger("MaxArchiveImages");
        $children = IPS_GetChildrenIDs($categoryID);
        if (count($children) <= $maxImages) return;

        usort($children, function ($a, $b) {
            $oa = @IPS_GetObject($a); $ob = @IPS_GetObject($b);
            if ($oa === false || $ob === false) return 0;
            return $ob['ObjectPosition'] <=> $oa['ObjectPosition'];
        });

        while (count($children) > $maxImages) {
            $oldestID = array_shift($children);
            if (@IPS_ObjectExists($oldestID) && IPS_MediaExists($oldestID)) {
                IPS_DeleteMedia($oldestID, true);
            }
        }
        $this->dbg('ARCHIVE', "Prune auf {$maxImages} für {$booleanIdent}");
    }

    /* =========================
     * ====== STREAM/SNAP ======
     * ========================= */

    private function CreateOrUpdateStream(string $ident, string $name)
    {
        $mediaID = @$this->GetIDForIdent($ident);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(3); // Stream
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetPosition($mediaID, 10);
            IPS_SetMediaCached($mediaID, true);
        }
        $url = $this->GetStreamURL();
        IPS_SetMediaFile($mediaID, $url, false);
        $this->dbg('STREAM', 'RTSP gesetzt', $this->maskSensitive($url));
    }

    private function GetStreamURL(): string
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));
        $streamType = $this->ReadPropertyString("StreamType");

        if ($streamType === "main") {
            return "rtsp://{$username}:{$password}@{$cameraIP}:554";
        }
        return "rtsp://{$username}:{$password}@{$cameraIP}:554/h264Preview_01_sub";
    }

    private function GetSnapshotURL(): string
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));
        return "http://{$cameraIP}/cgi-bin/api.cgi?cmd=Snap&user={$username}&password={$password}&width=1024&height=768";
    }

    /* =========================
     * ======== POLLING ========
     * ========================= */

    public function Polling()
    {
        if (!$this->isActive() || !$this->ReadPropertyBoolean("EnablePolling")) {
            $this->SetTimerInterval("PollingTimer", 0);
            return;
        }
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username  = urlencode($this->ReadPropertyString("Username"));
        $password  = urlencode($this->ReadPropertyString("Password"));
        $url = "http://{$cameraIP}/cgi-bin/api.cgi?cmd=GetAiState&rs=&user={$username}&password={$password}";

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->logInfo('POLLING', 'Abruf fehlgeschlagen.');
            return;
        }
        $this->dbg('POLLING', 'Rohdaten', $response);

        $data = json_decode($response, true);
        if ($data === null || !isset($data[0]['value'])) {
            $this->dbg('POLLING', 'Ungültige Daten', $response);
            return;
        }
        $ai = $data[0]['value'];
        $this->PollingUpdateState("dog_cat", $ai['dog_cat']['alarm_state']   ?? 0);
        $this->PollingUpdateState("people",  $ai['people']['alarm_state']    ?? 0);
        $this->PollingUpdateState("vehicle", $ai['vehicle']['alarm_state']   ?? 0);
    }

    private function PollingUpdateState(string $type, int $state)
    {
        $map = [ "dog_cat"=>"Tier", "people"=>"Person", "vehicle"=>"Fahrzeug" ];
        if (!isset($map[$type])) return;

        $ident = $map[$type];
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false) return;

        $cur = GetValue($vid);
        $new = ($state == 1);
        if ($cur != $new) {
            $this->SetValue($ident, $new);
            $this->dbg('POLLING', "Set {$ident} -> ".($new?'true':'false'));
            $timerName = $ident."_Reset";
            if ($new) {
                $this->SetTimerInterval($timerName, 5000);
                $this->CreateSnapshotAtPosition($ident, IPS_GetObject($vid)['ObjectPosition'] + 1);
            } else {
                $this->SetTimerInterval($timerName, 0);
            }
        }
    }

    /* =========================
     * ======== API/TOKEN ======
     * ========================= */

    private function apiBase(): string
    {
        $ip = $this->ReadPropertyString("CameraIP");
        return "http://{$ip}/api.cgi";
    }

    // HTTP-POST + Debug (immer aktiv, mit Kategorie)
    private function apiHttpPostJson(string $url, array $payload, string $cat = 'HTTP', bool $suppressError=false): ?array
    {
        $this->dbg($cat, "POST ".$url, $payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            if (!$suppressError) {
                $this->dbg($cat, "cURL error", $err);
                $this->logError($cat, "cURL-Fehler: ".$err);
            }
            return null;
        }
        curl_close($ch);

        $this->dbg($cat, "RAW", $raw);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function apiEnsureToken(): bool
    {
        if (!$this->isActive()) return false;
        $token = $this->ReadAttributeString("ApiToken");
        $exp   = (int)$this->ReadAttributeInteger("ApiTokenExpiresAt");
        if ($token === "" || time() >= ($exp - 30)) {
            $this->GetToken();
        }
        return $this->ReadAttributeString("ApiToken") !== "";
    }

    private function apiCallCat(string $cat, array $cmdPayload, bool $suppressError=false): ?array
    {
        if (!$this->isActive()) return null;
        if (!$this->apiEnsureToken()) return null;

        $token = $this->ReadAttributeString("ApiToken");
        $url   = $this->apiBase() . "?token={$token}";
        $resp  = $this->apiHttpPostJson($url, $cmdPayload, $cat, $suppressError);
        if (!$resp) return null;

        if (isset($resp[0]['code']) && (int)$resp[0]['code'] === 0) {
            return $resp;
        }

        $rsp = $resp[0]['error']['rspCode'] ?? null;
        if ((int)$rsp === -6) {
            $this->dbg($cat, "Auth -6 -> Token Refresh + Retry");
            $this->GetToken();
            $token2 = $this->ReadAttributeString("ApiToken");
            if ($token2) {
                $url2 = $this->apiBase() . "?token={$token2}";
                $resp2 = $this->apiHttpPostJson($url2, $cmdPayload, $cat, $suppressError);
                if (is_array($resp2) && isset($resp2[0]['code']) && (int)$resp2[0]['code'] === 0) {
                    return $resp2;
                }
                $resp = $resp2;
            }
        }

        if (!$suppressError) {
            $this->dbg($cat, "API FAIL", $resp);
            $this->logError($cat, "API-Befehl fehlgeschlagen");
        }
        return null;
    }

    // Backward helper (falls du irgendwo apiCall(...) verwendest)
    private function apiCall(array $cmdPayload, bool $suppressError=false): ?array
    {
        return $this->apiCallCat('API', $cmdPayload, $suppressError);
    }

    public function GetToken()
    {
        if (!$this->isActive()) {
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            return;
        }

        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");
        if ($cameraIP === "" || $username === "" || $password === "") {
            $this->logError('TOKEN', 'Unvollständige Einstellungen.');
            return;
        }

        $sem = "REOCAM_{$this->InstanceID}_GetToken";
        $entered = function_exists('IPS_SemaphoreEnter') ? IPS_SemaphoreEnter($sem, 5000) : true;
        if (!$entered) {
            $this->dbg('TOKEN', 'Übersprungen: paralleler Login aktiv.');
            return;
        }

        $this->WriteAttributeBoolean("TokenRefreshing", true);
        try {
            $url = "http://{$cameraIP}/api.cgi?cmd=Login";
            $data = [[
                "cmd"=>"Login",
                "param"=>["User"=>["Version"=>"0","userName"=>$username,"password"=>$password]]
            ]];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                $this->dbg('TOKEN', 'cURL-Fehler', $err);
                $this->logError('TOKEN', 'Login fehlgeschlagen: '.$err);
                return;
            }
            curl_close($ch);

            $this->dbg('TOKEN', 'Antwort', $response);

            $obj = json_decode($response, true);
            $token = $obj[0]['value']['Token']['name'] ?? null;
            if (!is_string($token) || $token === "") {
                $this->logError('TOKEN', 'Kein Token erhalten.');
                return;
            }

            $this->WriteAttributeString("ApiToken", $token);
            $this->WriteAttributeInteger("ApiTokenExpiresAt", time() + 3600 - 5);
            $this->SetTimerInterval("TokenRenewalTimer", 3000 * 1000);

            $this->logInfo('TOKEN', 'Token gespeichert, Erneuerungstimer gesetzt.');
        } finally {
            $this->WriteAttributeBoolean("TokenRefreshing", false);
            if (function_exists('IPS_SemaphoreLeave')) { IPS_SemaphoreLeave($sem); }
        }
    }

    /* =========================
     * ====== API VARS/UI ======
     * ========================= */

    private function CreateApiVariables()
    {
        $enableWhiteLed = $this->ReadPropertyBoolean("EnableApiWhiteLed");
        $enableEmail    = $this->ReadPropertyBoolean("EnableApiEmail");
        $enablePTZ      = $this->ReadPropertyBoolean("EnableApiPTZ");

        // Spotlight
        if ($enableWhiteLed) {
            if (!IPS_VariableProfileExists("REOCAM.WLED")) {
                IPS_CreateVariableProfile("REOCAM.WLED", 1);
                IPS_SetVariableProfileValues("REOCAM.WLED", 0, 2, 1);
                IPS_SetVariableProfileAssociation("REOCAM.WLED", 0, "Aus", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.WLED", 1, "Automatisch", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.WLED", 2, "Zeitabhängig", "", -1);
            }
            if (!@$this->GetIDForIdent("WhiteLed")) { $this->RegisterVariableBoolean("WhiteLed", "LED Status", "~Switch", 0); $this->EnableAction("WhiteLed"); }
            if (!@$this->GetIDForIdent("Mode"))     { $this->RegisterVariableInteger("Mode", "LED Modus", "REOCAM.WLED", 1); $this->EnableAction("Mode"); }
            if (!@$this->GetIDForIdent("Bright"))   { $this->RegisterVariableInteger("Bright", "LED Helligkeit", "~Intensity.100", 2); $this->EnableAction("Bright"); }
        } else {
            foreach (["WhiteLed","Mode","Bright"] as $id) if (@$this->GetIDForIdent($id)!==false) $this->UnregisterVariable($id);
        }

        // Email
        if ($enableEmail) {
            if (!IPS_VariableProfileExists("REOCAM.EmailInterval")) {
                IPS_CreateVariableProfile("REOCAM.EmailInterval", 1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 30,  "30 Sek.", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 60,  "1 Minute", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 300, "5 Minuten", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 600, "10 Minuten", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 1800,"30 Minuten", "", -1);
            }
            if (!IPS_VariableProfileExists("REOCAM.EmailContent")) {
                IPS_CreateVariableProfile("REOCAM.EmailContent", 1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 0, "Text", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 1, "Bild (ohne Text)", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 2, "Text + Bild", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 3, "Text + Video", "", -1);
            }
            if (!@$this->GetIDForIdent("EmailNotify"))  { $this->RegisterVariableBoolean("EmailNotify", "E-Mail Versand", "~Switch", 3); $this->EnableAction("EmailNotify"); }
            if (!@$this->GetIDForIdent("EmailInterval")){ $this->RegisterVariableInteger("EmailInterval", "E-Mail Intervall", "REOCAM.EmailInterval", 4); $this->EnableAction("EmailInterval"); }
            if (!@$this->GetIDForIdent("EmailContent")) { $this->RegisterVariableInteger("EmailContent", "E-Mail Inhalt", "REOCAM.EmailContent", 5); $this->EnableAction("EmailContent"); }
        } else {
            foreach (["EmailNotify","EmailInterval","EmailContent"] as $id) if (@$this->GetIDForIdent($id)!==false) $this->UnregisterVariable($id);
        }

        // PTZ HTML
        if ($enablePTZ) {
            if (!@$this->GetIDForIdent("PTZ_HTML")) {
                $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 8);
            }
            $this->CreateOrUpdatePTZHtml();
        } else {
            $id = @$this->GetIDForIdent("PTZ_HTML");
            if ($id !== false) $this->UnregisterVariable("PTZ_HTML");
        }
    }

    private function RemoveApiVariables(): void
    {
        foreach (["WhiteLed","Mode","Bright","EmailNotify","EmailInterval","EmailContent","PTZ_HTML"] as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false) $this->UnregisterVariable($ident);
        }
    }

    public function ExecuteApiRequests()
    {
        if (!$this->isActive() || !$this->apiEnsureToken()) return;

        if ($this->ReadPropertyBoolean("EnableApiWhiteLed")) $this->UpdateWhiteLedStatus();
        if ($this->ReadPropertyBoolean("EnableApiEmail"))    $this->UpdateEmailVars();
        if ($this->ReadPropertyBoolean("EnableApiPTZ"))      $this->CreateOrUpdatePTZHtml();
    }

    /* =========================
     * ======== SPOTLIGHT ======
     * ========================= */

    private function SendLedRequest(array $ledParams): bool
    {
        $payload = [[ "cmd"=>"SetWhiteLed", "param"=>[ "WhiteLed"=>array_merge($ledParams, ["channel"=>0]) ] ]];
        $res = $this->apiCallCat('SPOTLIGHT', $payload);
        return is_array($res) && ($res[0]['code'] ?? -1) === 0;
    }

    private function SetWhiteLed(bool $state): bool { return $this->SendLedRequest(['state'=>$state?1:0]); }
    private function SetMode(int $mode): bool       { return $this->SendLedRequest(['mode'=>$mode]); }
    private function SetBrightness(int $b): bool    { return $this->SendLedRequest(['bright'=>$b]); }

    private function UpdateWhiteLedStatus()
    {
        $resp = $this->apiCallCat('SPOTLIGHT', [[ "cmd"=>"GetWhiteLed", "action"=>0, "param"=>["channel"=>0] ]]);
        if (!$resp || !isset($resp[0]['value']['WhiteLed'])) {
            $this->dbg('SPOTLIGHT', 'Ungültige Antwort');
            return;
        }
        $white = $resp[0]['value']['WhiteLed'];
        $initialized = $this->ReadAttributeBoolean("ApiInitialized");
        $mapping = ['state'=>'WhiteLed','mode'=>'Mode','bright'=>'Bright'];
        foreach ($mapping as $jsonKey=>$ident) {
            if (!array_key_exists($jsonKey, $white)) continue;
            $new = $white[$jsonKey];
            $vid = @$this->GetIDForIdent($ident);
            if ($vid === false) continue;
            $cur = GetValue($vid);
            if (is_bool($cur)) $new = (bool)$new;
            if (!$initialized || $cur !== $new) {
                $this->SetValue($ident, $new);
                $this->dbg('SPOTLIGHT', "Update {$ident} -> ".json_encode($new));
            }
        }
        if (!$initialized) {
            $this->WriteAttributeBoolean("ApiInitialized", true);
            $this->dbg('SPOTLIGHT', 'Variablen initialisiert');
        }
    }

    /* =========================
     * ========== MAIL =========
     * ========================= */

    private function DetectEmailApiVersion(): string
    {
        $cached = $this->ReadAttributeString("EmailApiVersion");
        if ($cached === "V20" || $cached === "LEGACY") return $cached;
        $test = $this->apiCallCat('EMAIL', [[ "cmd"=>"GetEmailV20", "param"=>["channel"=>0] ]], true);
        $ver = (is_array($test) && ($test[0]['code'] ?? -1) === 0) ? "V20" : "LEGACY";
        $this->WriteAttributeString("EmailApiVersion", $ver);
        return $ver;
    }

    private function GetEmailEnabled(): ?bool
    {
        $v = $this->DetectEmailApiVersion();
        if ($v === 'V20') {
            $res = $this->apiCallCat('EMAIL', [[ "cmd"=>"GetEmailV20", "param"=>["channel"=>0] ]]);
            if (is_array($res)) return (bool)($res[0]['value']['Email']['enable'] ?? null);
        } else {
            $res = $this->apiCallCat('EMAIL', [[ "cmd"=>"GetEmail", "param"=>["channel"=>0] ]]);
            if (is_array($res)) return (bool)($res[0]['value']['Email']['schedule']['enable'] ?? null);
        }
        return null;
    }

    private function SetEmailEnabled(bool $enable): bool
    {
        $v = $this->DetectEmailApiVersion();
        if ($v === 'V20') {
            $res = $this->apiCallCat('EMAIL', [[ "cmd"=>"SetEmailV20", "param"=>[ "Email"=>["enable"=>$enable?1:0] ] ]]);
            return is_array($res) && ($res[0]['code'] ?? -1) === 0;
        }
        $res = $this->apiCallCat('EMAIL', [[ "cmd"=>"SetEmail", "param"=>[ "Email"=>["schedule"=>["enable"=>$enable?1:0]] ] ]]);
        return is_array($res) && ($res[0]['code'] ?? -1) === 0;
    }

    private function IntervalSecondsToString(int $sec): ?string
    {
        return [30=>"30 Seconds",60=>"1 Minute",300=>"5 Minutes",600=>"10 Minutes",1800=>"30 Minutes"][$sec] ?? null;
    }
    private function IntervalStringToSeconds(string $s): ?int
    {
        $map = ["30 Seconds"=>30,"1 Minute"=>60,"5 Minutes"=>300,"10 Minutes"=>600,"30 Minutes"=>1800];
        return $map[trim($s)] ?? null;
    }

    private function GetEmailInterval(): ?int
    {
        $v = $this->DetectEmailApiVersion();
        $res = ($v === 'V20')
            ? $this->apiCallCat('EMAIL', [[ "cmd"=>"GetEmailV20", "param"=>["channel"=>0] ]])
            : $this->apiCallCat('EMAIL', [[ "cmd"=>"GetEmail", "param"=>["channel"=>0] ]]);
        if (is_array($res) && isset($res[0]['value']['Email'])) {
            $e = $res[0]['value']['Email'];
            if (isset($e['intervalSec']) && is_numeric($e['intervalSec'])) return (int)$e['intervalSec'];
            if (isset($e['interval'])) {
                $sec = $this->IntervalStringToSeconds((string)$e['interval']);
                if ($sec !== null) return $sec;
            }
        }
        return null;
    }

    private function SetEmailInterval(int $sec): bool
    {
        $str = $this->IntervalSecondsToString($sec);
        if ($str === null) return false;
        $v = $this->DetectEmailApiVersion();
        $res = ($v === 'V20')
            ? $this->apiCallCat('EMAIL', [[ "cmd"=>"SetEmailV20", "param"=>[ "Email"=>["interval"=>$str] ] ]])
            : $this->apiCallCat('EMAIL', [[ "cmd"=>"SetEmail",    "param"=>[ "Email"=>["interval"=>$str] ] ]]);
        return is_array($res) && ($res[0]['code'] ?? -1) === 0;
    }

    private function GetEmailContent(): ?int
    {
        $v = $this->DetectEmailApiVersion();
        if ($v === 'V20') {
            $res = $this->apiCallCat('EMAIL', [[ "cmd"=>"GetEmailV20", "param"=>["channel"=>0] ]]);
            if (is_array($res) && isset($res[0]['value']['Email'])) {
                $e = $res[0]['value']['Email'];
                $text = (int)($e['textType'] ?? 1);
                $att  = (int)($e['attachmentType'] ?? 0);
                if (!$text && $att===1) return 1;
                if ( $text && $att===0) return 0;
                if ( $text && $att===1) return 2;
                if ( $text && $att===2) return 3;
                return 0;
            }
        } else {
            $res = $this->apiCallCat('EMAIL', [[ "cmd"=>"GetEmail", "param"=>["channel"=>0] ]]);
            if (is_array($res) && isset($res[0]['value']['Email']['attachment'])) {
                switch ($res[0]['value']['Email']['attachment']) {
                    case 'onlyPicture': return 1;
                    case 'picture':     return 2;
                    case 'video':       return 3;
                    default:            return 0;
                }
            }
            return 0;
        }
        return null;
    }

    private function SetEmailContent(int $mode): bool
    {
        $v = $this->DetectEmailApiVersion();
        if ($v === 'V20') {
            $payload = match ($mode) {
                0 => ["textType"=>1,"attachmentType"=>0],
                1 => ["textType"=>0,"attachmentType"=>1],
                2 => ["textType"=>1,"attachmentType"=>1],
                3 => ["textType"=>1,"attachmentType"=>2],
                default => null
            };
            if ($payload === null) return false;
            $res = $this->apiCallCat('EMAIL', [[ "cmd"=>"SetEmailV20", "param"=>[ "Email"=>$payload ] ]]);
            return is_array($res) && ($res[0]['code'] ?? -1) === 0;
        }
        $att = match ($mode) {
            0 => "0", 1 => "onlyPicture", 2 => "picture", 3 => "video", default => null
        };
        if ($att === null) return false;
        $res = $this->apiCallCat('EMAIL', [[ "cmd"=>"SetEmail", "param"=>[ "Email"=>["attachment"=>$att] ] ]]);
        return is_array($res) && ($res[0]['code'] ?? -1) === 0;
    }

    private function UpdateEmailStatusVar(): void
    {
        $id = @$this->GetIDForIdent("EmailNotify");
        if ($id === false) return;
        $val = $this->GetEmailEnabled();
        if ($val !== null) $this->SetValue("EmailNotify", $val);
    }

    private function UpdateEmailVars(): void
    {
        $id = @$this->GetIDForIdent("EmailNotify");
        if ($id !== false) { $v = $this->GetEmailEnabled(); if ($v !== null) $this->SetValue("EmailNotify", (bool)$v); }
        $id = @$this->GetIDForIdent("EmailInterval");
        if ($id !== false) { $v = $this->GetEmailInterval(); if ($v !== null) $this->SetValue("EmailInterval", (int)$v); }
        $id = @$this->GetIDForIdent("EmailContent");
        if ($id !== false) { $v = $this->GetEmailContent(); if ($v !== null) $this->SetValue("EmailContent", (int)$v); }
    }

    /* =========================
     * =========== PTZ =========
     * ========================= */

    // --- Die PTZ-HTML & Kommandos bleiben funktional, verwenden aber dbg/log + Kategorie 'PTZ'
    // (Aus Platzgründen nicht erneut vollständig kommentiert – Funktionslogik wie gehabt)

    private function CreateOrUpdatePTZHtml(): void
    {
        if (!@$this->GetIDForIdent("PTZ_HTML")) {
            $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 8);
        }
        $hook = $this->ReadAttributeString("CurrentHook");
        if ($hook === "") $hook = $this->RegisterHook();

        $presets = $this->getPresetList();
        $rows = '';
        if (!empty($presets)) {
            foreach ($presets as $p) {
                $pid = (int)$p['id'];
                $title = htmlspecialchars((string)$p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $rows .= '<div class="preset-row" data-preset="'.$pid.'">'
                       . '<button class="preset" data-preset="'.$pid.'" title="'.$title.'">'.$title.'</button>'
                       . '<div class="icons">'
                       . '<button class="icon rename" data-preset="'.$pid.'" title="Umbenennen" aria-label="Umbenennen">✎</button>'
                       . '<button class="icon del" data-preset="'.$pid.'" title="Löschen" aria-label="Löschen">🗑</button>'
                       . '</div>'
                       . '</div>';
            }
        } else {
            $rows = '<div class="no-presets">Keine Presets gefunden.</div>';
        }

        $zInfo = $this->getZoomInfo();
        $zMin = is_array($zInfo) ? ($zInfo['min'] ?? 0) : 0;
        $zMax = is_array($zInfo) ? ($zInfo['max'] ?? 27) : 27;
        $zPos = is_array($zInfo) ? ($zInfo['pos'] ?? $zMin) : $zMin;

        $btn = 42; $gap = 6;
        $html = <<<HTML
<div id="ptz-wrap" style="font-family:system-ui,Segoe UI,Roboto,Arial; overflow:hidden;">
<style>
#ptz-wrap{ --btn: {$btn}px; --gap: {$gap}px; --fs: 16px; --radius:10px; max-width:520px; margin:0 auto; user-select:none; }
#ptz-wrap .grid{ display:grid; grid-template-columns:repeat(3,var(--btn)); grid-template-rows:repeat(3,var(--btn)); gap:var(--gap); justify-content:center; align-items:center; margin-bottom:10px;}
#ptz-wrap button{ height:var(--btn); border:1px solid #cfcfcf; border-radius:var(--radius); background:#f8f8f8; font-size:var(--fs); line-height:1; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.06); box-sizing:border-box; padding:6px 10px;}
#ptz-wrap .dir{ width:var(--btn); padding:0; }
#ptz-wrap .up{grid-column:2;grid-row:1;} .left{grid-column:1;grid-row:2;} #ptz-wrap .right{grid-column:3;grid-row:2;} .down{grid-column:2;grid-row:3;}
#ptz-wrap .section-title{ font-weight:600; margin:10px 0 6px; }
#ptz-wrap .preset-row{ display:flex; align-items:center; gap:8px; margin-bottom:var(--gap); }
#ptz-wrap .preset{ flex:1; height:auto; min-height:36px; padding:8px 12px; text-align:left; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#ptz-wrap .icons{ display:flex; gap:6px; }
#ptz-wrap .icon{ width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; padding:0; font-size:18px; }
#ptz-wrap .no-presets{ opacity:.7; padding:4px 0; }
#ptz-wrap .new{ margin-top:10px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
#ptz-wrap .new input[type="text"]{ flex:1; min-width:160px; height:34px; padding:4px 8px; border:1px solid #cfcfcf; border-radius:8px; }
#ptz-wrap .new button{ height:36px; padding:6px 10px; }
</style>

<div class="grid">
  <button data-dir="up" class="dir up" title="Hoch" aria-label="Hoch">▲</button>
  <button data-dir="left" class="dir left" title="Links" aria-label="Links">◀</button>
  <button data-dir="right" class="dir right" title="Rechts" aria-label="Rechts">▶</button>
  <button data-dir="down" class="dir down" title="Runter" aria-label="Runter">▼</button>
</div>

<div class="section-title">Zoom</div>
<div class="zoomline" style="margin:6px 0 12px; text-align:center;">
  <input type="range" id="ptz-zoom" min="{$zMin}" max="{$zMax}" step="1" value="{$zPos}" style="width:220px;">
</div>

<div class="section-title">Presets</div>
<div class="presets">{$rows}</div>

<div class="section-title">Neues Preset</div>
<div class="new">
  <input type="text" id="ptz-new-name" maxlength="32" placeholder="Name eingeben …"/>
  <button id="ptz-new-save" title="Aktuelle Position als neues Preset speichern">Speichern</button>
</div>

<script>
(function(){
  var base = "{$hook}";
  var wrap = document.getElementById("ptz-wrap");
  function call(op, extra){
    var qs = new URLSearchParams(extra || {}); qs.set("ptz", op);
    var url = base + "?" + qs.toString();
    return fetch(url, { method: "GET", credentials: "same-origin", cache: "no-store" })
           .then(function(r){ return r.text().catch(function(){ return ""; }); })
           .then(function(t){ return ((t||"").trim().toUpperCase() !== "ERROR"); })
           .catch(function(){ return true; });
  }
  var zoomEl = document.getElementById("ptz-zoom");
  if (zoomEl) zoomEl.addEventListener("change", function(){ var v = parseInt(zoomEl.value,10); if(!isNaN(v)) call("zoompos", {pos:v}); });

  wrap.addEventListener("click", function(ev){
    var btn = ev.target.closest("button"); if (!btn) return;
    if (btn.hasAttribute("data-dir")) { call(btn.getAttribute("data-dir")); return; }
    if (btn.classList.contains("preset") && btn.hasAttribute("data-preset")) { call("preset:" + btn.getAttribute("data-preset")); return; }
    if (btn.classList.contains("rename") && btn.hasAttribute("data-preset")) {
      var id = parseInt(btn.getAttribute("data-preset")||"0",10);
      var cur = (btn.parentElement && btn.parentElement.previousElementSibling) ? btn.parentElement.previousElementSibling.textContent.trim() : "";
      var neu = window.prompt("Neuer Name für Preset " + id + ":", cur);
      if (neu && neu.trim() !== "") call("rename", {id:id, name:neu.trim()});
      return;
    }
    if (btn.classList.contains("del") && btn.hasAttribute("data-preset")) {
      var idd = parseInt(btn.getAttribute("data-preset")||"0",10);
      if (window.confirm("Preset " + idd + " löschen?")) call("delete", {id: idd});
      return;
    }
    if (btn.id === "ptz-new-save") {
      var nm = (document.getElementById("ptz-new-name").value || "").trim();
      if (!nm) return;
      var rows = wrap.querySelectorAll(".preset-row[data-preset]"); var max=-1;
      rows.forEach(function(el){ var v = parseInt(el.getAttribute("data-preset")||"-1",10); if(!isNaN(v)&&v>max) max=v; });
      call("save", { id: (max+1), name: nm });
      document.getElementById("ptz-new-name").value = "";
      return;
    }
  });
})();
</script>
HTML;

        $this->setHtmlIfChanged("PTZ_HTML", $html);
    }

    private function setHtmlIfChanged(string $ident, string $html): void
    {
        $id = @$this->GetIDForIdent($ident);
        $old = ($id !== false) ? GetValue($id) : null;
        if (!is_string($old) || $old !== $html) $this->SetValue($ident, $html);
    }

    private function HandlePtzCommand(string $cmd): bool
    {
        $stepParam = isset($_REQUEST['step']) ? max(1, (int)$_REQUEST['step']) : 1;
        $idParam   = $_REQUEST['id']   ?? null;
        $nameParam = $_REQUEST['name'] ?? null;
        $id = is_null($idParam) ? null : (int)$idParam;
        $name = is_null($nameParam) ? null : (string)$nameParam;

        if (strpos($cmd, 'preset:') === 0) {
            $pid = (int)substr($cmd, 7);
            return $pid >= 0 ? $this->ptzGotoPreset($pid) : false;
        }
        switch (strtolower($cmd)) {
            case 'save':
                if ($id === null || $id < 0) return false;
                if (is_string($name)) {
                    $name = trim($name);
                    if ($name === '') $name = null;
                    if ($name !== null) {
                        $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $name);
                        $name = mb_substr($name, 0, 32, 'UTF-8');
                    }
                } else $name = null;
                return $this->PTZ_SavePreset($id, $name);
            case 'rename':
                if ($id === null || $id < 0 || !is_string($name) || trim($name) === '') return false;
                $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', trim($name));
                $name = mb_substr($name, 0, 32, 'UTF-8');
                return $this->PTZ_RenamePreset($id, $name);
            case 'delete':
                if ($id === null || $id < 0) return false;
                return $this->PTZ_DeletePreset($id);
            case 'zoomin':  return $this->ptzZoom('in',  $stepParam);
            case 'zoomout': return $this->ptzZoom('out', $stepParam);
            case 'zoompos':
                if (!isset($_REQUEST['pos'])) return false;
                $pos = (int)$_REQUEST['pos'];
                $info = $this->getZoomInfo();
                if (is_array($info)) $pos = max($info['min'], min($info['max'], $pos));
                return $this->setZoomPos($pos);
        }
        $map = [ 'left'=>'Left','right'=>'Right','up'=>'Up','down'=>'Down' ];
        $k = strtolower($cmd);
        if (!isset($map[$k])) return false;
        return $this->ptzCtrl($map[$k]);
    }

    private function getPtzStyle(): string { $s = $this->ReadAttributeString("PtzStyle"); return ($s === "flat" || $s === "nested") ? $s : ""; }
    private function setPtzStyle(string $s): void { if ($s === "flat" || $s === "nested") $this->WriteAttributeString("PtzStyle", $s); }

    private function postCmdDual(string $cmd, array $body, ?string $nestedKey=null, bool $suppress=false): ?array
    {
        $nestedKey = $nestedKey ?: $cmd;
        $known = $this->getPtzStyle(); // "flat"|"nested"|"" (unknown)
        $order = $known ? [$known, ($known === 'flat' ? 'nested' : 'flat')] : ['flat','nested'];
        foreach ($order as $mode) {
            $payload = [[ 'cmd'=>$cmd, 'param'=> ($mode==='flat') ? $body : [$nestedKey=>$body] ]];
            $resp = $this->apiCallCat('PTZ', $payload, true);
            if (is_array($resp) && (($resp[0]['code'] ?? -1) === 0)) {
                if ($known !== $mode) $this->setPtzStyle($mode);
                return $resp;
            }
        }
        if (!$suppress) $this->logError('PTZ', "postCmdDual FAIL für {$cmd}");
        return null;
    }

    private function ptzCtrl(string $op, array $extra = [], int $pulseMs = 250): bool
    {
        $param = ['channel'=>0,'op'=>$op] + $extra;
        $isMove = in_array($op, ['Left','Right','Up','Down'], true);
        if ($isMove && !isset($param['speed'])) $param['speed'] = 5;

        $ok = is_array($this->postCmdDual('PtzCtrl', $param, 'PtzCtrl', false));
        if (!$ok) return false;

        if ($isMove) {
            IPS_Sleep($pulseMs);
            $this->postCmdDual('PtzCtrl', ['channel'=>0,'op'=>'Stop'], 'PtzCtrl', true);
        }
        return true;
    }

    private function ptzGotoPreset(int $id): bool
    {
        $ok = is_array($this->postCmdDual('PtzCtrl', ['channel'=>0,'op'=>'ToPos','id'=>$id], 'PtzCtrl', true));
        if ($ok) return true;
        $ok = is_array($this->postCmdDual('PtzCtrl', ['channel'=>0,'op'=>'ToPreset','id'=>$id], 'PtzCtrl', true));
        return (bool)$ok;
    }

    private function collectPresetsRecursive($node, array &$out, array &$seen): void
    {
        if (!is_array($node) || empty($node)) return;
        $first = reset($node);
        if (is_array($first) && (isset($first['id']) || isset($first['Id']))) {
            foreach ($node as $p) {
                if (!is_array($p)) continue;
                $rawId = $p['id'] ?? $p['Id'] ?? null;
                if (!is_numeric($rawId)) continue;
                $id = (int)$rawId;
                if (isset($seen[$id])) continue;
                $en = $p['enable'] ?? $p['Enable'] ?? null;
                if ($en !== null && (int)$en === 0) continue;
                $name = (string)($p['name'] ?? $p['Name'] ?? $p['sName'] ?? $p['label'] ?? $p['presetName'] ?? '');
                $trim = trim($name);
                $flag = $p['exist'] ?? $p['bExist'] ?? $p['bexistPos'] ?? $p['enable'] ?? $p['enabled'] ?? $p['set'] ?? $p['bSet'] ?? null;
                $isSet = ($flag === 1 || $flag === '1' || $flag === true);
                $posArr = $p['pos'] ?? $p['position'] ?? $p['ptzpos'] ?? $p['ptz'] ?? null;
                $hasPos = false;
                if (is_array($posArr)) {
                    foreach ($posArr as $v) { if (is_numeric($v) && (float)$v != 0.0) { $hasPos = true; break; } }
                }
                $isGeneric = ($trim !== '') && (preg_match('/^(pos|preset|position)\s*0*\d+$/i', $trim) === 1);
                if (($trim === '' || $isGeneric) && !$isSet && !$hasPos) continue;
                if ($trim === '') $name = "Preset ".$id;
                $out[] = ['id'=>$id,'name'=>$name]; $seen[$id] = true;
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) $this->collectPresetsRecursive($v, $out, $seen);
        }
    }

    private function getPresetList(): array
    {
        $res = $this->postCmdDual('GetPtzPreset', ['channel'=>0], 'GetPtzPreset', /*suppress*/ true);
        $list = []; $seen = [];
        if (is_array($res)) {
            $v = $res[0]['value'] ?? [];
            $ps = $v['PtzPreset']['preset'] ?? $v['PtzPreset']['table'] ?? $v['preset'] ?? $v['table'] ?? null;
            if (is_array($ps)) {
                $this->collectPresetsRecursive($ps, $list, $seen);
            } else {
                $this->collectPresetsRecursive($v, $list, $seen);
            }
            if (empty($list)) {
                $this->collectPresetsRecursive($res, $list, $seen);
            }
            if (empty($list)) {
                $this->dbg('PTZ', 'Keine Presets erkannt', $res, true);
            }
        } else {
            $this->dbg('PTZ', 'Kein gueltiges Array-Response', null, true);
        }
        usort($list, fn($a,$b) => $a['id'] <=> $b['id']);
        return $list;
    }

    private function ptzSetPreset(int $id, ?string $nameForCreate=null): bool
    {
        $entry = ['id'=>$id, 'enable'=>1];
        if ($nameForCreate !== null && $nameForCreate !== '') {
            $n = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $nameForCreate);
            $entry['name'] = mb_substr($n, 0, 32, 'UTF-8');
        }

        $ok = is_array($this->postCmdDual('SetPtzPreset', ['channel'=>0, 'table'=>[ $entry ]], 'PtzPreset', /*suppress*/true));
        if (!$ok) {
            $flat = ['channel'=>0, 'id'=>$id, 'enable'=>1] + (isset($entry['name'])?['name'=>$entry['name']]:[]);
            $ok = is_array($this->postCmdDual('SetPtzPreset', $flat, 'PtzPreset', /*suppress*/true));
        }
        if (!$ok) $this->dbg('PTZ', 'SetPtzPreset fehlgeschlagen', $entry);
        return (bool)$ok;
    }

    private function ptzClearPreset(int $id): bool
    {
        $entry = ['id'=>$id, 'enable'=>0, 'name'=>''];
        $ok = is_array($this->postCmdDual('SetPtzPreset', ['channel'=>0,'table'=>[$entry]], 'PtzPreset', /*suppress*/true));
        if (!$ok) {
            $ok = is_array($this->postCmdDual('SetPtzPreset', ['channel'=>0,'id'=>$id,'enable'=>0,'name'=>''], 'PtzPreset', /*suppress*/true));
        }
        if (!$ok) $this->dbg('PTZ', 'Preset clear gescheitert', ['id'=>$id]);
        return (bool)$ok;
    }

    private function ptzRenamePreset(int $id, string $name): bool
    {
        $name = trim($name);
        if ($name === '') return false;
        $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $name);
        $name = mb_substr($name, 0, 32, 'UTF-8');

        $ok = is_array($this->postCmdDual('SetPtzPreset', ['channel'=>0, 'table'=>[ ['id'=>$id, 'name'=>$name] ]], 'PtzPreset', /*suppress*/true));
        if (!$ok) {
            $ok = is_array($this->postCmdDual('SetPtzPreset', ['channel'=>0, 'id'=>$id, 'name'=>$name], 'PtzPreset', /*suppress*/true));
        }
        if (!$ok) {
            $ok = is_array($this->postCmdDual('PtzPreset', ['channel'=>0,'id'=>$id,'name'=>$name,'cmd'=>'SetName'], 'PtzPreset', /*suppress*/true))
              ?: is_array($this->postCmdDual('PtzCtrl', ['channel'=>0,'op'=>'SetPresetName','id'=>$id,'name'=>$name], 'PtzCtrl', /*suppress*/true));
        }
        if (!$ok) $this->dbg('PTZ', "Rename fehlgeschlagen", ['id'=>$id, 'name'=>$name]);
        return (bool)$ok;
    }

    public function PTZ_SavePreset(int $id, ?string $name=null): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzSetPreset($id);
        if ($ok && $name) { $this->ptzRenamePreset($id, $name); }
        $this->CreateOrUpdatePTZHtml();
        return $ok;
    }

    public function PTZ_RenamePreset(int $id, string $name): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzRenamePreset($id, $name);
        if ($ok) $this->CreateOrUpdatePTZHtml();
        return $ok;
    }

    public function PTZ_DeletePreset(int $id): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzClearPreset($id);
        if ($ok) $this->CreateOrUpdatePTZHtml();
        return $ok;
    }

    private function getZoomInfo(): ?array
    {
        $res = $this->postCmdDual('GetZoomFocus', ['channel'=>0], 'ZoomFocus', /*suppress*/ false);
        if (!is_array($res) || !isset($res[0]['value'])) return null;
        $val  = $res[0]['value'];
        $zf   = $val['ZoomFocus'] ?? $val['zoomFocus'] ?? null;
        $zoom = is_array($zf) ? ($zf['zoom'] ?? $zf['Zoom'] ?? null) : null;
        if (!is_array($zoom)) return null;
        $pos = (int)($zoom['pos'] ?? $zoom['Pos'] ?? 0);
        $min = (int)($zoom['min'] ?? $zoom['Min'] ?? 0);
        $max = (int)($zoom['max'] ?? $zoom['Max'] ?? 10);
        return ['pos'=>$pos, 'min'=>$min, 'max'=>$max];
    }

    private function setZoomPos(int $pos): bool
    {
        $info = $this->getZoomInfo() ?: ['min'=>0, 'max'=>10];
        $min = (int)$info['min']; $max = (int)$info['max'];
        $p = max($min, min($max, $pos));
        $sendPos = $p;
        if ($max > 10) {
            $sendPos = (int)round(($p - $min) * 10 / max(1, $max - $min));
        }
        $res = $this->postCmdDual('StartZoomFocus', ['channel'=>0, 'op'=>'ZoomPos', 'pos'=>$sendPos], 'ZoomFocus', /*suppress*/ true);
        return is_array($res) && (($res[0]['code'] ?? -1) === 0);
    }

    private function ptzZoom(string $dir, int $step = 1): bool
    {
        $step = max(1, min(27, $step));
        $pulseMs = 160; $gapMs = 60;
        $ops = ($dir === 'in') ? ['ZoomIn','ZoomTele','ZoomAdd'] : ['ZoomOut','ZoomWide','ZoomDec'];
        $okAny = false;
        for ($i = 0; $i < $step; $i++) {
            $ok = false;
            foreach ($ops as $op) {
                $res = $this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>$op], 'PtzCtrl', /*suppress*/ true);
                if (is_array($res) && (($res[0]['code'] ?? -1) === 0)) {
                    $ok = $okAny = true;
                    IPS_Sleep($pulseMs);
                    $this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'Stop'], 'PtzCtrl', /*suppress*/ true);
                    break;
                }
            }
            if (!$ok) break;
            if ($i < $step-1) IPS_Sleep($gapMs);
        }
        return $okAny;
    }

    // ---------------------------
    // RequestAction (UI-Steuerung)
    // ---------------------------
    public function RequestAction($Ident, $Value)
    {
        if (!$this->isActive()) {
            $this->dbg("UI", "Instanz inaktiv – Aktion verworfen", $Ident);
            return;
        }

        switch ($Ident) {
            case "WhiteLed":
                $ok = $this->SetWhiteLed((bool)$Value);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (bool)$Value); }
                else     { $this->UpdateWhiteLedStatus(); }
                break;

            case "Mode":
                $ok = $this->SetMode((int)$Value);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (int)$Value); }
                else     { $this->UpdateWhiteLedStatus(); }
                break;

            case "Bright":
                $ok = $this->SetBrightness((int)$Value);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (int)$Value); }
                else     { $this->UpdateWhiteLedStatus(); }
                break;

            case "EmailNotify":
                $ok = $this->SetEmailEnabled((bool)$Value);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (bool)$Value); }
                else     { $this->UpdateEmailStatusVar(); }
                break;

            case "EmailInterval":
                $ok = $this->SetEmailInterval((int)$Value);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (int)$Value); }
                else     { $this->UpdateEmailVars(); }
                break;

            case "EmailContent":
                $ok = $this->SetEmailContent((int)$Value);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (int)$Value); }
                else     { $this->UpdateEmailVars(); }
                break;

            default:
                throw new Exception("Invalid Ident");
        }
    }

    private function UpdateEmailStatusVar(): void
    {
        $id = @$this->GetIDForIdent("EmailNotify");
        if ($id === false) return;
        $val = $this->GetEmailEnabled();
        if ($val !== null) $this->SetValue("EmailNotify", $val);
    }
}

