<?php
declare(strict_types=1);

class Reolink extends IPSModule
{
    // ---------------------------
    // Lifecycle
    // ---------------------------
    public function Create()
    {
        parent::Create();

        // Basis
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StreamType", "sub");
        $this->RegisterPropertyBoolean("InstanceStatus", true);

        // Sichtbarkeit / UI
        $this->RegisterPropertyBoolean("ShowMoveVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);
        $this->RegisterPropertyBoolean("ShowArchives", true);
        $this->RegisterPropertyBoolean("ShowTestElements", false);
        $this->RegisterPropertyBoolean("ShowVisitorElements", false);

        // Polling
        $this->RegisterPropertyBoolean("EnablePolling", false);
        $this->RegisterPropertyInteger("PollingInterval", 2);

        // API-Feature-Schalter
        $this->RegisterPropertyBoolean("EnableApiWhiteLed", true);
        $this->RegisterPropertyBoolean("EnableApiEmail", true);
        $this->RegisterPropertyBoolean("EnableApiPTZ", false);
        $this->RegisterPropertyBoolean("EnableApiFTP", false);
        $this->RegisterPropertyBoolean('EnableApiAlarm', true); 
        $this->RegisterPropertyBoolean('EnableApiSiren', true); 


        // Archiv
        $this->RegisterPropertyInteger("MaxArchiveImages", 20);

        // Attribute
        $this->RegisterAttributeBoolean("ApiInitialized", false);
        $this->RegisterAttributeBoolean("TokenRefreshing", false);
        $this->RegisterAttributeInteger("ApiTokenExpiresAt", 0);
        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString("ApiToken", "");
        $this->RegisterAttributeString("EmailApiVersion", "");
        $this->RegisterAttributeString("PtzStyle", "");
        $this->RegisterAttributeString("PtzPresetsCache", "");
        $this->RegisterAttributeString("AbilityCache", "");


        // Timer
        $this->RegisterTimer("Person_Reset",   0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Person");');
        $this->RegisterTimer("Tier_Reset",     0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Tier");');
        $this->RegisterTimer("Fahrzeug_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Fahrzeug");');
        $this->RegisterTimer("Bewegung_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Bewegung");');
        $this->RegisterTimer("Test_Reset",     0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Test");');
        $this->RegisterTimer("Besucher_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Besucher");');

        $this->RegisterTimer("PollingTimer",      0, 'REOCAM_Polling($_IPS[\'TARGET\']);');
        $this->RegisterTimer("ApiRequestTimer",   0, 'REOCAM_ExecuteApiRequests($_IPS[\'TARGET\']);');
        $this->RegisterTimer("TokenRenewalTimer", 0, 'REOCAM_GetToken($_IPS[\'TARGET\']);');
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
            // Token & Flags leeren
            $this->WriteAttributeString("ApiToken", "");
            $this->WriteAttributeInteger("ApiTokenExpiresAt", 0);
            $this->WriteAttributeBoolean("TokenRefreshing", false);
            return;
        }

        $this->SetStatus(102); // IS_ACTIVE

        // Hook sicherstellen
        $hookPath = $this->ReadAttributeString("CurrentHook");
        if ($hookPath === "") {
            $hookPath = $this->RegisterHook();
            $this->dbg('WEBHOOK', 'Hook init', ['path' => $hookPath, 'full' => $this->BuildWebhookFullUrl($hookPath)]);
        }

        // Stream
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");

        // UI-Elemente
        if ($this->ReadPropertyBoolean("ShowMoveVariables")) { $this->CreateMoveVariables(); } else { $this->RemoveMoveVariables(); }
        if (!$this->ReadPropertyBoolean("ShowSnapshots")) { $this->RemoveSnapshots(); }
        if ($this->ReadPropertyBoolean("ShowArchives")) { $this->CreateOrUpdateArchives(); } else { $this->RemoveArchives(); }
        if ($this->ReadPropertyBoolean("ShowTestElements")) { $this->CreateTestElements(); } else { $this->RemoveTestElements(); }
        if ($this->ReadPropertyBoolean("ShowVisitorElements")) { $this->CreateVisitorElements(); } else { $this->RemoveVisitorElements(); }

        // Polling
        if ($this->ReadPropertyBoolean("EnablePolling")) {
            $interval = max(1, (int)$this->ReadPropertyInteger("PollingInterval"));
            $this->SetTimerInterval("PollingTimer", $interval * 1000);
        } else {
            $this->SetTimerInterval("PollingTimer", 0);
        }

        // API-Schalter
        $enableWhiteLed = $this->ReadPropertyBoolean("EnableApiWhiteLed");
        $enableEmail    = $this->ReadPropertyBoolean("EnableApiEmail");
        $enablePTZ      = $this->ReadPropertyBoolean("EnableApiPTZ");
        $enableFTP      = $this->ReadPropertyBoolean("EnableApiFTP");
        $enableAlarm    = $this->ReadPropertyBoolean("EnableApiAlarm");
        $enableSiren    = $this->ReadPropertyBoolean("EnableApiSiren");
        $anyFeatureOn = ($enableWhiteLed || $enableEmail || $enablePTZ || $enableFTP || $enableAlarm || $enableSiren);


        if ($anyFeatureOn) {
            $this->SetTimerInterval("ApiRequestTimer", 10 * 1000);
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            $this->CreateOrUpdateApiVariablesUnified();
            $this->GetToken();
            $this->ExecuteApiRequests();
        } else {
            $this->SetTimerInterval("ApiRequestTimer", 0);
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            $this->CreateOrUpdateApiVariablesUnified();
        }
    }

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
                $ok = $this->EmailApply((bool)$Value, null, null);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (bool)$Value); }
                else     { $this->EmailApply(null, null, null); } // zurücklesen
                break;

            case "EmailInterval":
                $ok = $this->EmailApply(null, (int)$Value, null);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (int)$Value); }
                else     { $this->EmailApply(null, null, null); }
                break;

            case "EmailContent":
                $ok = $this->EmailApply(null, null, (int)$Value);
                if ($ok) { SetValue($this->GetIDForIdent($Ident), (int)$Value); }
                else     { $this->EmailApply(null, null, null); }
                break;

            case "FTPEnabled":
                $ok = $this->FtpApply((bool)$Value);
                if ($ok) {
                    SetValue($this->GetIDForIdent($Ident), (bool)$Value);
                } else {
                    $this->UpdateFtpStatus(); // zurücklesen, falls Set scheitert
                }
                break;

            case 'MdSensitivity':
                $lvl = max(1, min(50, (int)$Value));   // <-- 1..50
                $ok = $this->SetMdSensitivity($lvl);
                if ($ok) {
                    $this->SetValue('MdSensitivity', $lvl);
                }
                break;

            case 'SirenEnabled':
                $ok = $this->SetSirenEnabled((bool)$Value);
                if ($ok) {
                    $this->SetValue('SirenEnabled', (bool)$Value);
                }
                break;

            default:
                throw new Exception("Invalid Ident");
        }
    }

    private function isActive(): bool
    {
        return $this->ReadPropertyBoolean("InstanceStatus") && ($this->GetStatus() === 102);
    }

    // ---------------------------
    // DEBUG / LOGGING (immer aktiv, mit Redaction)
    // ---------------------------
    private function dbg(string $topic, string $message, $data = null, bool $ignored = false): void
    {
        // $ignored ist nur für API-Kompatibilität zu älterem Code; Debug ist immer aktiv.
        $label = strtoupper($topic);
        $text  = $message;
        if ($data !== null) {
            $text .= ' | ' . $this->toStr($this->redactDeep($data));
        }
        // 0 => "string"
        $this->SendDebug($label, $text, 0);
    }

    private function toStr($v): string
    {
        if (is_string($v)) return $v;
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function redactDeep($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $lk = strtolower((string)$k);

                if (in_array($lk, ['password','pass','pwd','token','apikey','authorization','auth','bearer','secret'], true)) {
                    $out[$k] = '***';

                } elseif (in_array($lk, ['user','username'], true)) {
                    // Nur skalare Werte maskieren; bei Arrays rekursiv weitergehen
                    if (is_scalar($v) || (is_object($v) && method_exists($v, '__toString'))) {
                        $out[$k] = $this->maskMiddle((string)$v);
                    } else {
                        $out[$k] = $this->redactDeep($v);
                    }

                } else {
                    $out[$k] = $this->redactDeep($v);
                }
            }
            return $out;
        }

        if (is_string($value)) {
            $s = $value;
            $s = preg_replace('/([?&])(user|username)=([^&#\s]*)/i', '$1$2=***', $s);
            $s = preg_replace('/([?&])(password|pass|pwd)=([^&#\s]*)/i', '$1$2=***', $s);
            $s = preg_replace('/([?&])(token|apikey)=([^&#\s]*)/i', '$1$2=***', $s);
            $s = preg_replace('/(Authorization:\s*Bearer\s+)[^\s"]+/i', '$1***', $s);
            $s = preg_replace('/(Authorization:\s*Basic\s+)[A-Za-z0-9+\/=]+/i', '$1***', $s);
            return $s;
        }

        return $value;
    }

    private function maskMiddle(string $s): string
    {
        if ($s === '') return '';
        if (mb_strlen($s) <= 2) return str_repeat('*', mb_strlen($s));
        return mb_substr($s, 0, 1) . '***' . mb_substr($s, -1);
    }

    // ---------------------------
    // Webhook + Formular
    // ---------------------------
    public function GetConfigurationForm()
    {
        $formPath = __DIR__ . '/form.json';
        $form = file_exists($formPath) ? json_decode(file_get_contents($formPath), true) : ['elements' => []];

        $hookPath = $this->ReadAttributeString("CurrentHook");
        if ($hookPath === '') {
            $hookPath = $this->RegisterHook();
        }

        $full = $this->BuildWebhookFullUrl($hookPath);

        $head = [
            [
                "type"    => "Label",
                "name"    => "WebhookFull",
                "caption" => "Webhook für Kamerakonfiguration: " . $full
            ]
        ];

        array_splice($form['elements'], 0, 0, $head);
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

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            $this->dbg('WEBHOOK', 'Keine WebHook-Control-Instanz gefunden');
            return $hookPath;
        }
        $hookInstanceID = $ids[0];

        $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);
        if (!is_array($hooks)) $hooks = [];

        foreach ($hooks as $hook) {
            if (($hook['Hook'] ?? '') === $hookPath && ($hook['TargetID'] ?? 0) === $this->InstanceID) {
                $this->dbg('WEBHOOK', 'Bereits registriert', ['path' => $hookPath]);
                return $hookPath;
            }
        }

        $hooks[] = ['Hook' => $hookPath, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookInstanceID);

        $this->dbg('WEBHOOK', 'Registriert', ['path' => $hookPath, 'full' => $this->BuildWebhookFullUrl($hookPath)]);
        return $hookPath;
    }

    private function getLocalIPv4(string $probe='8.8.8.8:53'): string {
        $ip = '127.0.0.1';
        if ($sock = @stream_socket_client('udp://'.$probe, $e1, $e2, 1)) {
            $name = stream_socket_get_name($sock, false);
            fclose($sock);
            if (is_string($name) && ($p = strrpos($name, ':')) !== false) {
                $ip = substr($name, 0, $p);
            }
        }
        return $ip;
    }

    private function BuildWebhookFullUrl(string $hookPath): string
    {
        $host = $this->getLocalIPv4();   
        $port = 3777;   
        return "http://{$host}:{$port}{$hookPath}";
    }

    public function ProcessHookData()
    {
        if (!$this->ReadPropertyBoolean("InstanceStatus") || $this->GetStatus() !== 102) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('HTTP/1.1 204 No Content');
            return;
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }

        $ptz = null;
        $raw = @file_get_contents('php://input');

        // JSON-Payload
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['ptz'])) {
                $ptz = (string)$data['ptz'];
                if (isset($data['id']))   $_REQUEST['id'] = $data['id'];
                if (isset($data['name'])) $_REQUEST['name'] = $data['name'];
            }
        }
        // Query / Form
        if ($ptz === null) {
            if (isset($_POST['ptz'])) { $ptz = (string)$_POST['ptz']; }
            elseif (isset($_GET['ptz'])) { $ptz = (string)$_GET['ptz']; }
        }

        if ($ptz !== null) {
            $this->dbg('WEBHOOK', 'PTZ-Call', ['ptz' => $ptz, 'id' => $_REQUEST['id'] ?? '', 'name' => $_REQUEST['name'] ?? '']);
            $ok = $this->HandlePtzCommand($ptz);
            header('Content-Type: text/plain; charset=utf-8');
            echo $ok ? "OK" : "ERROR";
            return;
        }

        // Alarm/AI-Daten
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $this->dbg('WEBHOOK', 'Alarmdaten empfangen', $data);
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

    // ---------------------------
    // Bewegungen / Snapshots / Archiv
    // ---------------------------
    private function ProcessAllData($data)
    {
        if (!isset($data['alarm']['type'])) return;
        $type = $data['alarm']['type'];

        switch ($type) {
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
        $timerName = $ident . "_Reset";
        $this->dbg('POLLING', "Setze '$ident' auf true");
        $this->SetValue($ident, true);
        $this->SetTimerInterval($timerName, 5000);
    }

    public function ResetMoveTimer(string $ident)
    {
        $timerName = $ident . "_Reset";
        $this->dbg('POLLING', "Reset '$ident' → false");
        $this->SetValue($ident, false);
        $this->SetTimerInterval($timerName, 0);
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

    private function CreateTestElements()
    {
        $this->RegisterVariableBoolean("Test", "Test", "~Motion", 50);

        if (!IPS_ObjectExists(@$this->GetIDForIdent("Snapshot_Test"))) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, "Snapshot_Test");
            IPS_SetName($mediaID, "Snapshot Test");
            IPS_SetMediaCached($mediaID, false);
        }

        if (!IPS_ObjectExists(@$this->GetIDForIdent("Archive_Test"))) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, "Archive_Test");
            IPS_SetName($categoryID, "Bildarchiv Test");
        }
    }

    private function RemoveTestElements()
    {
        $id = @$this->GetIDForIdent("Test");
        if ($id) $this->UnregisterVariable("Test");

        $mid = @$this->GetIDForIdent("Snapshot_Test");
        if ($mid) IPS_DeleteMedia($mid, true);

        $cid = @$this->GetIDForIdent("Archive_Test");
        if ($cid) {
            foreach (IPS_GetChildrenIDs($cid) as $childID) {
                IPS_DeleteMedia($childID, true);
            }
            IPS_DeleteCategory($cid);
        }
    }

    private function CreateVisitorElements()
    {
        $this->RegisterVariableBoolean("Besucher", "Besucher erkannt", "~Motion", 50);

        if (!IPS_ObjectExists(@$this->GetIDForIdent("Snapshot_Besucher"))) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, "Snapshot_Besucher");
            IPS_SetName($mediaID, "Snapshot Besucher");
            IPS_SetMediaCached($mediaID, false);
        }

        if (!IPS_ObjectExists(@$this->GetIDForIdent("Archive_Besucher"))) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, "Archive_Besucher");
            IPS_SetName($categoryID, "Bildarchiv Besucher");
        }
    }

    private function RemoveVisitorElements()
    {
        $id = @$this->GetIDForIdent("Besucher");
        if ($id) $this->UnregisterVariable("Besucher");

        $mid = @$this->GetIDForIdent("Snapshot_Besucher");
        if ($mid) IPS_DeleteMedia($mid, true);

        $cid = @$this->GetIDForIdent("Archive_Besucher");
        if ($cid) {
            foreach (IPS_GetChildrenIDs($cid) as $childID) {
                IPS_DeleteMedia($childID, true);
            }
            IPS_DeleteCategory($cid);
        }
    }

    private function CreateSnapshotAtPosition(string $booleanIdent, int $position)
    {
        if (!$this->ReadPropertyBoolean("ShowTestElements") && $booleanIdent === "Test") return;
        if (!$this->ReadPropertyBoolean("ShowVisitorElements") && $booleanIdent === "Besucher") return;

        $snapshotIdent = "Snapshot_" . $booleanIdent;
        $mediaID = @$this->GetIDForIdent($snapshotIdent);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $snapshotIdent);
            IPS_SetPosition($mediaID, $position);
            IPS_SetName($mediaID, "Snapshot von " . $booleanIdent);
            IPS_SetMediaCached($mediaID, false);
            $this->dbg('SNAPSHOT', "Neues Medienobjekt", ['ident' => $snapshotIdent, 'mediaID' => $mediaID]);
        }

        $snapshotUrl = $this->GetSnapshotURL(); // enthält user/pass → wird geschwärzt
        $fileName = $booleanIdent . "_" . $mediaID . ".jpg";
        $filePath = IPS_GetKernelDir() . "media/" . $fileName;

        $this->dbg('SNAPSHOT', 'Abrufen', ['url' => $snapshotUrl]);
        $imageData = @file_get_contents($snapshotUrl);
        if ($imageData !== false) {
            IPS_SetMediaFile($mediaID, $filePath, false);
            IPS_SetMediaContent($mediaID, base64_encode($imageData));
            IPS_SendMediaEvent($mediaID);

            $this->dbg('SNAPSHOT', "Erstellt", ['boolean' => $booleanIdent, 'file' => $fileName]);

            if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                $archiveCategoryID = $this->CreateOrGetArchiveCategory($booleanIdent);
                $this->CreateArchiveSnapshot($booleanIdent, $archiveCategoryID);
            }
        } else {
            $this->dbg('SNAPSHOT', "Fehler beim Abrufen", ['boolean' => $booleanIdent]);
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

    private function RemoveArchives()
    {
        foreach (["Person","Tier","Fahrzeug","Bewegung","Besucher","Test"] as $cat) {
            $archiveIdent = "Archive_" . $cat;
            $cid = @$this->GetIDForIdent($archiveIdent);
            if ($cid && IPS_ObjectExists($cid)) {
                // alle Medien in der Kategorie löschen
                foreach (IPS_GetChildrenIDs($cid) as $childID) {
                    if (IPS_MediaExists($childID)) {
                        IPS_DeleteMedia($childID, true);
                    } else {
                        @IPS_DeleteObject($childID);
                    }
                }
                IPS_DeleteCategory($cid);
                $this->dbg('SNAPSHOT', 'Archiv-Kategorie entfernt', ['ident' => $archiveIdent, 'id' => $cid]);
            }
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
            $pos = [
                "Person" => 22, "Tier" => 27, "Fahrzeug" => 32,
                "Bewegung" => 37, "Besucher" => 42, "Test" => 47
            ][$booleanIdent] ?? 99;
            IPS_SetPosition($categoryID, $pos);
        }
        return $categoryID;
    }

    private function PruneArchive(int $categoryID, string $booleanIdent)
    {
        $maxImages = max(0, (int)$this->ReadPropertyInteger("MaxArchiveImages"));
        $children  = IPS_GetChildrenIDs($categoryID);

        $this->dbg('SNAPSHOT', 'Archiv prüfen', ['cat' => $booleanIdent, 'anzahl' => count($children), 'max' => $maxImages]);

        if (count($children) > $maxImages) {
            usort($children, function ($a, $b) {
                $A = @IPS_GetObject($a) ?: ['ObjectPosition' => 0];
                $B = @IPS_GetObject($b) ?: ['ObjectPosition' => 0];
                return $B['ObjectPosition'] <=> $A['ObjectPosition'];
            });

            while (count($children) > $maxImages) {
                $oldestID = array_shift($children);
                if (@IPS_ObjectExists($oldestID) && IPS_MediaExists($oldestID)) {
                    IPS_DeleteMedia($oldestID, true);
                    $this->dbg('SNAPSHOT', 'Archiv: entfernt', ['id' => $oldestID]);
                }
            }
        }
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
            $this->dbg('SNAPSHOT', 'Archivbild erstellt', ['boolean' => $booleanIdent, 'mediaID' => $mediaID]);
            $this->PruneArchive($categoryID, $booleanIdent);
        } else {
            $this->dbg('SNAPSHOT', "Archivbild fehlgeschlagen", ['boolean' => $booleanIdent]);
        }
    }

    private function CreateOrUpdateStream(string $ident, string $name)
    {
        $mediaID = @$this->GetIDForIdent($ident);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(3);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetPosition($mediaID, 10);
            IPS_SetMediaCached($mediaID, true);
        }
        IPS_SetMediaFile($mediaID, $this->GetStreamURL(), false);
    }

    private function GetStreamURL(): string
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));
        $streamType = $this->ReadPropertyString("StreamType");
        return $streamType === "main"
            ? "rtsp://$username:$password@$cameraIP:554"
            : "rtsp://$username:$password@$cameraIP:554/h264Preview_01_sub";
    }

    private function GetSnapshotURL(): string
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));
        return "http://$cameraIP/cgi-bin/api.cgi?cmd=Snap&user=$username&password=$password&width=1024&height=768";
    }

    // ---------------------------
    // Polling (AI State)
    // ---------------------------
    public function Polling()
    {
        if (!$this->isActive() || !$this->ReadPropertyBoolean("EnablePolling")) {
            $this->SetTimerInterval("PollingTimer", 0);
            return;
        }
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));

        $url = "http://$cameraIP/cgi-bin/api.cgi?cmd=GetAiState&rs=&user=$username&password=$password";
        $this->dbg('POLLING', 'Abruf', ['url' => $url]);

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->dbg("POLLING", "Fehler beim Abrufen der Daten");
            return;
        }

        $this->dbg("POLLING", "Rohdaten", $this->redactDeep($response));

        $data = json_decode($response, true);
        if ($data === null || !isset($data[0]['value'])) {
            $this->dbg("POLLING", "Ungueltige Daten", $response);
            return;
        }

        $aiState = $data[0]['value'];
        $this->PollingUpdateState("dog_cat", $aiState['dog_cat']['alarm_state'] ?? 0);
        $this->PollingUpdateState("people",  $aiState['people']['alarm_state']  ?? 0);
        $this->PollingUpdateState("vehicle", $aiState['vehicle']['alarm_state'] ?? 0);
    }

    private function PollingUpdateState(string $type, int $state)
    {
        $mapping = [ "dog_cat" => "Tier", "people" => "Person", "vehicle" => "Fahrzeug" ];
        if (!isset($mapping[$type])) return;

        $ident = $mapping[$type];
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID === false) return;

        $currentValue = (bool)GetValue($variableID);
        $newValue     = ($state == 1);

        if ($currentValue !== $newValue) {
            $this->SetValue($ident, $newValue);
            $this->dbg('POLLING', "Setze '$ident'", ['value' => $newValue]);
            $timerName = $ident . "_Reset";
            if ($newValue) {
                $this->SetTimerInterval($timerName, 5000);
                $this->dbg("POLLING", "Snapshot trigger fuer '$ident'");
                $this->CreateSnapshotAtPosition($ident, IPS_GetObject($variableID)['ObjectPosition'] + 1);
            } else {
                $this->SetTimerInterval($timerName, 0);
            }
        }
    }

    // ---------------------------
    // API / HTTP / Token
    // ---------------------------
    private function apiBase(): string
    {
        $ip = $this->ReadPropertyString("CameraIP");
        return "http://{$ip}/api.cgi";
    }

    private function apiHttpPostJson(string $url, array $payload, string $topic = 'API', bool $suppressError = false): ?array
    {
        // Kurzinfo
        $this->dbg($topic, "HTTP POST", ['url' => $url]);

        // Request/Response (bereits geschwärzt über dbg)
        $this->dbg($topic, "REQUEST", $payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload)
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            if (!$suppressError) {
                $this->dbg($topic, "cURL error", $err);
                $this->LogMessage("Reolink/$topic: cURL-Fehler: $err", KL_ERROR);
            }
            return null;
        }
        curl_close($ch);

        $this->dbg($topic, "RAW", $this->redactDeep($raw));

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

    private function apiCall(array $cmdPayload, string $topic = 'API', bool $suppressError = false): ?array
    {
        if (!$this->isActive()) return null;
        if (!$this->apiEnsureToken()) return null;

        $token = $this->ReadAttributeString("ApiToken");
        $url   = $this->apiBase() . "?token={$token}";
        $resp  = $this->apiHttpPostJson($url, $cmdPayload, $topic, $suppressError);
        if (!$resp) return null;

        if (isset($resp[0]['code']) && (int)$resp[0]['code'] === 0) {
            return $resp;
        }

        $rsp = $resp[0]['error']['rspCode'] ?? null;
        if ((int)$rsp === -6) {
            $this->dbg($topic, "Auth -6 → Token Refresh + Retry");
            $this->GetToken();
            $token2 = $this->ReadAttributeString("ApiToken");
            if ($token2) {
                $url2 = $this->apiBase() . "?token={$token2}";
                $resp2 = $this->apiHttpPostJson($url2, $cmdPayload, $topic, $suppressError);
                if (is_array($resp2) && isset($resp2[0]['code']) && (int)$resp2[0]['code'] === 0) {
                    return $resp2;
                }
                $resp = $resp2;
            }
        }

        if (!$suppressError) {
            $this->dbg($topic, "API FAIL", $resp);
            $this->LogMessage("Reolink/$topic: API-Befehl fehlgeschlagen: ".json_encode($resp), KL_ERROR);
        }
        return null;
    }

    public function GetToken()
    {
        if (!$this->isActive()) {
            $this->dbg('TOKEN', 'Abgebrochen: Instanz inaktiv');
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            return;
        }
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");
        if ($cameraIP === "" || $username === "" || $password === "") {
            $this->dbg('TOKEN', 'Abgebrochen: Unvollständige Einstellungen');
            return;
        }

        $semName = "REOCAM_{$this->InstanceID}_GetToken";
        $entered = function_exists('IPS_SemaphoreEnter') ? IPS_SemaphoreEnter($semName, 5000) : true;
        if (!$entered) {
            $this->dbg('TOKEN', 'Übersprungen: anderer Login aktiv');
            return;
        }

        $this->WriteAttributeBoolean("TokenRefreshing", true);
        try {
            $url = "http://{$cameraIP}/api.cgi?cmd=Login";
            $payload = [[
                "cmd"   => "Login",
                "param" => ["User" => [
                    "Version"  => "0",
                    "userName" => $username,
                    "password" => $password
                ]]
            ]];

            $this->dbg('TOKEN', 'Login', ['url' => $url]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 8
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                $this->dbg('TOKEN', 'cURL-Fehler', $err);
                $this->LogMessage("Reolink/TOKEN: cURL-Fehler beim Login: $err", KL_ERROR);
                return;
            }
            curl_close($ch);

            $this->dbg('TOKEN', 'RAW', $this->redactDeep($response));

            $responseData = json_decode($response, true);
            $token = $responseData[0]['value']['Token']['name'] ?? null;
            if (!is_string($token) || $token === "") {
                $this->dbg('TOKEN', 'Ungueltige Antwort (kein Token)', $responseData);
                $this->LogMessage("Reolink/TOKEN: Fehler beim Abrufen des Tokens: ".$response, KL_ERROR);
                return;
            }

            $this->WriteAttributeString("ApiToken", $token);
            $this->WriteAttributeInteger("ApiTokenExpiresAt", time() + 3600 - 5);
            $this->SetTimerInterval("TokenRenewalTimer", 3000 * 1000);
            $this->dbg('TOKEN', 'Token gespeichert; Erneuerungstimer gesetzt');
        } finally {
            $this->WriteAttributeBoolean("TokenRefreshing", false);
            if (function_exists('IPS_SemaphoreLeave')) {
                IPS_SemaphoreLeave($semName);
            }
        }
    }

    // ---------------------------
    // Variablen an/abbauen (API)
    // ---------------------------
 
    // ---- Zentrale Anlage / Löschung aller API-Variablen ----
    private function CreateOrUpdateApiVariablesUnified(): void
    {
        // -------- White LED --------
        if ($this->ReadPropertyBoolean("EnableApiWhiteLed")) {
            // Profil für Modus sicherstellen
            if (!IPS_VariableProfileExists("REOCAM.WLED")) {
                IPS_CreateVariableProfile("REOCAM.WLED", 1); // Integer
            }
            IPS_SetVariableProfileValues("REOCAM.WLED", 0, 2, 1);
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 0, "Aus", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 1, "Automatisch", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 2, "Zeitabhängig", "", -1);

            // WhiteLed (bool)
            $id = @$this->GetIDForIdent("WhiteLed");
            if ($id === false) {
                $this->RegisterVariableBoolean("WhiteLed", "LED Status", "~Switch", 0);
                $this->EnableAction("WhiteLed");
            } else {
                IPS_SetName($id, "LED Status");
                IPS_SetPosition($id, 0);
                IPS_SetVariableCustomProfile($id, "~Switch");
                $this->EnableAction("WhiteLed");
            }

            // Mode (int, Profil REOCAM.WLED)
            $id = @$this->GetIDForIdent("Mode");
            if ($id === false) {
                $this->RegisterVariableInteger("Mode", "LED Modus", "REOCAM.WLED", 1);
                $this->EnableAction("Mode");
            } else {
                IPS_SetName($id, "LED Modus");
                IPS_SetPosition($id, 1);
                IPS_SetVariableCustomProfile($id, "REOCAM.WLED");
                $this->EnableAction("Mode");
            }

            // Bright (int, 0..100)
            $id = @$this->GetIDForIdent("Bright");
            if ($id === false) {
                $this->RegisterVariableInteger("Bright", "LED Helligkeit", "~Intensity.100", 2);
                $this->EnableAction("Bright");
            } else {
                IPS_SetName($id, "LED Helligkeit");
                IPS_SetPosition($id, 2);
                IPS_SetVariableCustomProfile($id, "~Intensity.100");
                $this->EnableAction("Bright");
            }
        } else {
            if (@$this->GetIDForIdent("WhiteLed") !== false)   $this->UnregisterVariable("WhiteLed");
            if (@$this->GetIDForIdent("Mode") !== false)       $this->UnregisterVariable("Mode");
            if (@$this->GetIDForIdent("Bright") !== false)     $this->UnregisterVariable("Bright");
        }

        // -------- Email --------
        if ($this->ReadPropertyBoolean("EnableApiEmail")) {
            // Profile sicherstellen
            if (!IPS_VariableProfileExists("REOCAM.EmailInterval")) {
                IPS_CreateVariableProfile("REOCAM.EmailInterval", 1);
            }
            // Werte/Assoziationen
            IPS_SetVariableProfileValues("REOCAM.EmailInterval", 30, 1800, 1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 30,   "30 Sek.",    "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 60,   "1 Minute",   "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 300,  "5 Minuten",  "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 600,  "10 Minuten", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 1800, "30 Minuten", "", -1);

            if (!IPS_VariableProfileExists("REOCAM.EmailContent")) {
                IPS_CreateVariableProfile("REOCAM.EmailContent", 1);
            }
            IPS_SetVariableProfileValues("REOCAM.EmailContent", 0, 3, 1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 0, "Text",              "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 1, "Bild (ohne Text)",  "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 2, "Text + Bild",       "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 3, "Text + Video",      "", -1);

            // Variablen
            $id = @$this->GetIDForIdent("EmailNotify");
            if ($id === false) {
                $this->RegisterVariableBoolean("EmailNotify", "E-Mail Versand", "~Switch", 3);
                $this->EnableAction("EmailNotify");
            } else {
                IPS_SetName($id, "E-Mail Versand");
                IPS_SetPosition($id, 3);
                IPS_SetVariableCustomProfile($id, "~Switch");
                $this->EnableAction("EmailNotify");
            }

            $id = @$this->GetIDForIdent("EmailInterval");
            if ($id === false) {
                $this->RegisterVariableInteger("EmailInterval", "E-Mail Intervall", "REOCAM.EmailInterval", 4);
                $this->EnableAction("EmailInterval");
            } else {
                IPS_SetName($id, "E-Mail Intervall");
                IPS_SetPosition($id, 4);
                IPS_SetVariableCustomProfile($id, "REOCAM.EmailInterval");
                $this->EnableAction("EmailInterval");
            }

            $id = @$this->GetIDForIdent("EmailContent");
            if ($id === false) {
                $this->RegisterVariableInteger("EmailContent", "E-Mail Inhalt", "REOCAM.EmailContent", 5);
                $this->EnableAction("EmailContent");
            } else {
                IPS_SetName($id, "E-Mail Inhalt");
                IPS_SetPosition($id, 5);
                IPS_SetVariableCustomProfile($id, "REOCAM.EmailContent");
                $this->EnableAction("EmailContent");
            }
        } else {
            if (@$this->GetIDForIdent("EmailNotify") !== false)   $this->UnregisterVariable("EmailNotify");
            if (@$this->GetIDForIdent("EmailInterval") !== false) $this->UnregisterVariable("EmailInterval");
            if (@$this->GetIDForIdent("EmailContent") !== false)  $this->UnregisterVariable("EmailContent");
        }

        // -------- PTZ (HTML Box) --------
        if ($this->ReadPropertyBoolean("EnableApiPTZ")) {
            $id = @$this->GetIDForIdent("PTZ_HTML");
            if ($id === false) {
                $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 8);
            } else {
                IPS_SetName($id, "PTZ");
                IPS_SetPosition($id, 8);
                IPS_SetVariableCustomProfile($id, "~HTMLBox");
            }
            // UI aktualisieren
            $this->CreateOrUpdatePTZHtml();
        } else {
            if (@$this->GetIDForIdent("PTZ_HTML") !== false) $this->UnregisterVariable("PTZ_HTML");
        }

        // -------- FTP --------
        if ($this->ReadPropertyBoolean("EnableApiFTP")) {
            $id = @$this->GetIDForIdent("FTPEnabled");
            if ($id === false) {
                $this->RegisterVariableBoolean("FTPEnabled", "FTP-Upload", "~Switch", 6);
                $this->EnableAction("FTPEnabled");
            } else {
                IPS_SetName($id, "FTP-Upload");
                IPS_SetPosition($id, 6);
                IPS_SetVariableCustomProfile($id, "~Switch");
                $this->EnableAction("FTPEnabled");
            }
        } else {
            if (@$this->GetIDForIdent("FTPEnabled") !== false) $this->UnregisterVariable("FTPEnabled");
        }

        // -------- Bewegungssensitivität (1..50) --------
        if ($this->ReadPropertyBoolean("EnableApiAlarm")) {
            if (!IPS_VariableProfileExists("REOCAM.Sensitivity50")) {
                IPS_CreateVariableProfile("REOCAM.Sensitivity50", 1); // Integer
            }
            IPS_SetVariableProfileValues("REOCAM.Sensitivity50", 1, 50, 1);

            $id = @$this->GetIDForIdent("MdSensitivity");
            if ($id === false) {
                $this->RegisterVariableInteger("MdSensitivity", "Bewegung Sensitivität", "REOCAM.Sensitivity50", 9);
                $this->EnableAction("MdSensitivity");
            } else {
                IPS_SetName($id, "Bewegung Sensitivität");
                IPS_SetPosition($id, 9);
                IPS_SetVariableCustomProfile($id, "REOCAM.Sensitivity50");
                $this->EnableAction("MdSensitivity");
            }
        } else {
            if (@$this->GetIDForIdent("MdSensitivity") !== false) $this->UnregisterVariable("MdSensitivity");
        }

        // -------- Sirene --------
        if ($this->ReadPropertyBoolean("EnableApiSiren")) {
            $id = @$this->GetIDForIdent("SirenEnabled");
            if ($id === false) {
                $this->RegisterVariableBoolean("SirenEnabled", "Sirene aktiv", "~Switch", 10);
                $this->EnableAction("SirenEnabled");
            } else {
                IPS_SetName($id, "Sirene aktiv");
                IPS_SetPosition($id, 10);
                IPS_SetVariableCustomProfile($id, "~Switch");
                $this->EnableAction("SirenEnabled");
            }
        } else {
            if (@$this->GetIDForIdent("SirenEnabled") !== false) $this->UnregisterVariable("SirenEnabled");
        }
    }

    public function ExecuteApiRequests()
    {
        if (!$this->isActive()) return;
        if (!$this->apiEnsureToken()) return;

        if ($this->ReadPropertyBoolean("EnableApiWhiteLed")) {
            $this->UpdateWhiteLedStatus();
        }
        if ($this->ReadPropertyBoolean("EnableApiEmail")) {
            $this->EmailApply(null, null, null);
        }
        if ($this->ReadPropertyBoolean("EnableApiPTZ")) {
            $this->CreateOrUpdatePTZHtml(false);
        }
        if ($this->ReadPropertyBoolean("EnableApiFTP")) {
            $this->UpdateFtpStatus();
        }
        if ($this->ReadPropertyBoolean("EnableApiAlarm")) {
            $this->UpdateMdSensitivityStatus(); 
        }
        if ($this->ReadPropertyBoolean("EnableApiSiren")) {
            $this->UpdateSirenStatus(); 
        }
    }

    // ---- Ability Cache + zentrale Versionserkennung ----
    private function apiGetAbilityCached(): array
    {
        // sicherstellen, dass das Attribut existiert (idempotent)
        $this->RegisterAttributeString("AbilityCache", "");

        $attrName = 'AbilityCache';
        $raw = @$this->ReadAttributeString($attrName);
        $now = time();

        if (is_string($raw) && $raw !== '') {
            $obj = @json_decode($raw, true);
            if (is_array($obj) && isset($obj['ts']) && ($now - (int)$obj['ts'] < 300)) {
                return (array)$obj['ability'];
            }
        }

        $res = $this->apiCall([
            ["cmd"=>"GetAbility","param"=>["User"=>["userName"=>$this->ReadPropertyString("Username")]]]
        ], 'ABILITY', /*suppress*/ true);

        $ability = is_array($res) ? ($res[0]['value']['Ability'] ?? []) : [];
        $this->WriteAttributeString($attrName, json_encode(['ts'=>$now,'ability'=>$ability]));
        return $ability;
    }

    /**
     * $domain:
     *  - 'schedule' -> V20/LEGACY via Ability.scheduleVersion.ver
     *  - 'email'    -> probe GetEmailV20
     *  - 'ftp'      -> probe GetFtpV20 / GetFtp
     *  - 'audio'    -> probe GetAudioAlarmV20 / GetAudioAlarm
     */
    private function ApiVersion(string $domain): string
    {
        $ability = $this->apiGetAbilityCached();

        if ($domain === 'schedule') {
            $ver = (int)($ability['scheduleVersion']['ver'] ?? 0);
            return ($ver === 1) ? 'V20' : 'LEGACY';
        }

        // Für API-Domänen, die Reolink nicht zuverlässig in Ability ausweist – Probe Calls:
        switch ($domain) {
            case 'email':
                $t = $this->apiCall([[ "cmd"=>"GetEmailV20", "param"=>["channel"=>0] ]], 'EMAIL', true);
                return (is_array($t) && (($t[0]['code'] ?? -1) === 0)) ? 'V20' : 'LEGACY';

            case 'ftp':
                $t = $this->apiCall([[ "cmd"=>"GetFtpV20", "param"=>["channel"=>0] ]], 'FTP', true);
                if (is_array($t) && (($t[0]['code'] ?? -1) === 0)) return 'V20';
                $t = $this->apiCall([[ "cmd"=>"GetFtp", "param"=>["channel"=>0] ]], 'FTP', true);
                return (is_array($t) && (($t[0]['code'] ?? -1) === 0)) ? 'LEGACY' : 'NONE';

            case 'audio':
                $t = $this->apiCall([[ "cmd"=>"GetAudioAlarmV20", "param"=>["channel"=>0], "action"=>1 ]], 'AUDIO', true);
                if (is_array($t) && (($t[0]['code'] ?? -1) === 0)) return 'V20';
                $t = $this->apiCall([[ "cmd"=>"GetAudioAlarm", "param"=>["channel"=>0], "action"=>1 ]], 'AUDIO', true);
                return (is_array($t) && (($t[0]['code'] ?? -1) === 0)) ? 'LEGACY' : 'NONE';
        }

        return 'NONE';
    }

    // ---- Profile-Helfer ----
    private function ensureIntegerProfile(string $name, int $min, int $max, int $step, array $assocs = []): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1); // 1 = Integer
        }
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        // Associations zurücksetzen (sonst sammeln sich alte an)
        // Workaround: Profil einmal löschen & neu anlegen – oder Associations gezielt setzen:
        // Wir setzen hier nur die gewünschten:
        // (Keine API für "clear" – bei Bedarf einfach neu erstellen)
        foreach ($assocs as $val => $text) {
            IPS_SetVariableProfileAssociation($name, (int)$val, (string)$text, "", -1);
        }
    }

    private function ensureBoolProfile(string $name, string $offCaption = 'Aus', string $onCaption = 'An'): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 0); // 0 = Boolean
        }
        // Bei Bool-Profilen werden Associations über 0/1 gesetzt:
        IPS_SetVariableProfileAssociation($name, 0, $offCaption, "", -1);
        IPS_SetVariableProfileAssociation($name, 1, $onCaption,  "", -1);
    }

    // ---- Variablen-Helfer ----
    private function ensureVarBoolean(string $ident, string $name, string $profile, int $pos, bool $enableAction = true): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            $this->RegisterVariableBoolean($ident, $name, $profile, $pos);
            if ($enableAction) $this->EnableAction($ident);
        }
    }

    private function ensureVarInteger(string $ident, string $name, string $profile, int $pos, bool $enableAction = true): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            $this->RegisterVariableInteger($ident, $name, $profile, $pos);
            if ($enableAction) $this->EnableAction($ident);
        }
    }

    private function ensureVarString(string $ident, string $name, string $profile, int $pos): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            $this->RegisterVariableString($ident, $name, $profile, $pos);
        }
    }

    private function removeVars(array $idents): void
    {
        foreach ($idents as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false) {
                $this->UnregisterVariable($ident);
            }
        }
    }



    // ---------------------------
    // Spotlight (White LED)
    // ---------------------------
    private function SendLedRequest(array $ledParams): bool
    {
        $payload = [[
            "cmd"   => "SetWhiteLed",
            "param" => [ "WhiteLed" => array_merge($ledParams, ["channel" => 0]) ]
        ]];
        $res = $this->apiCall($payload, 'SPOTLIGHT');
        return is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0;
    }
    private function SetWhiteLed(bool $state): bool   { return $this->SendLedRequest(['state' => $state ? 1 : 0]); }
    private function SetMode(int $mode): bool         { return $this->SendLedRequest(['mode'  => $mode]); }
    private function SetBrightness(int $b): bool      { return $this->SendLedRequest(['bright'=> $b]); }

    private function UpdateWhiteLedStatus(): void
    {
        $resp = $this->apiCall([[ "cmd"=>"GetWhiteLed", "action"=>0, "param"=>["channel"=>0] ]], 'SPOTLIGHT');
        if (!is_array($resp) || !isset($resp[0]['value']['WhiteLed'])) {
            $this->dbg('SPOTLIGHT', 'Ungültige Antwort', $resp ?? null);
            return;
        }
        $wl = $resp[0]['value']['WhiteLed'];
        $state = [
            'state'  => isset($wl['state'])  ? (int)$wl['state']  : null,
            'mode'   => isset($wl['mode'])   ? (int)$wl['mode']   : null,
            'bright' => isset($wl['bright']) ? (int)$wl['bright'] : null,
        ];
        $this->ApplyWhiteLedStateToVars($state);
    }

    private function ApplyWhiteLedStateToVars(array $led): void
    {
        $map = [
            'WhiteLed' => array_key_exists('state',  $led) ? (bool)$led['state']   : null,
            'Mode'     => array_key_exists('mode',   $led) ? (int)$led['mode']     : null,
            'Bright'   => array_key_exists('bright', $led) ? (int)$led['bright']   : null,
        ];

        foreach ($map as $ident => $newVal) {
            if ($newVal === null) {
                continue;
            }
            $id = @$this->GetIDForIdent($ident);
            if ($id === false) {
                continue;
            }
            $oldVal = GetValue($id);
            if ($oldVal !== $newVal) {
                $this->SetValue($ident, $newVal);
                $this->dbg('SPOTLIGHT', 'Var geändert', ['ident' => $ident, 'old' => $oldVal, 'new' => $newVal]);
            } else {
                $this->dbg('SPOTLIGHT', 'Unverändert', ['ident' => $ident, 'value' => $newVal], true);
            }
        }
    }

    // ---------------------------
    // E-Mail (V20 / Legacy)
    // ---------------------------

    private function IntervalSecondsToString(int $sec): ?string
    {
        switch ($sec) {
            case 30: return "30 Seconds";
            case 60: return "1 Minute";
            case 300: return "5 Minutes";
            case 600: return "10 Minutes";
            case 1800: return "30 Minutes";
        }
        return null;
    }
    private function IntervalStringToSeconds(string $s): ?int
    {
        $s = trim($s);
        $map = [ "30 Seconds" => 30, "1 Minute" => 60, "5 Minutes" => 300, "10 Minutes" => 600, "30 Minutes" => 1800 ];
        return $map[$s] ?? null;
    }

    private function ApplyEmailStateToVars(array $st): void
    {
        $fields = [
            ['ident' => 'EmailNotify',   'key' => 'enabled',     'cast' => 'bool'],
            ['ident' => 'EmailInterval', 'key' => 'intervalSec', 'cast' => 'int'],
            ['ident' => 'EmailContent',  'key' => 'contentMode', 'cast' => 'int'], // 0..3
        ];

        foreach ($fields as $f) {
            $key = $f['key'];
            if (!array_key_exists($key, $st) || $st[$key] === null) {
                continue; // nichts zu setzen
            }

            $id = @$this->GetIDForIdent($f['ident']);
            if ($id === false) {
                continue; // Variable existiert (noch) nicht
            }

            $old = GetValue($id);
            $new = $st[$key];

            if ($f['cast'] === 'bool') {
                $new = (bool)$new;
            } else { // int
                $new = (int)$new;
            }

            if ($old !== $new) {
                $this->SetValue($f['ident'], $new);
                $this->dbg('EMAIL', 'Var geändert', ['ident' => $f['ident'], 'old' => $old, 'new' => $new]);
            }
            else {
                $this->dbg('EMAIL', 'Unverändert', ['ident' => $f['ident'], 'value' => $new], true);
            }
        }
    }

    // Liest den Email-Zustand GENAU EINMAL und normalisiert ihn
    private function GetEmailState(): ?array
    {
        $ver = $this->ApiVersion('email');

        if ($ver === 'V20') {
            $res = $this->apiCall([[ "cmd"=>"GetEmailV20", "param"=>["channel"=>0] ]], 'EMAIL');
            if (!is_array($res) || !isset($res[0]['value']['Email'])) {
                $this->dbg('EMAIL', 'GetEmailV20: ungültige Antwort', $res);
                return null;
            }
            $e = $res[0]['value']['Email'];

            $enabled = isset($e['enable']) ? ((int)$e['enable'] === 1) : null;

            // Intervall: bevorzugt Sekunden, sonst String → Sekunden
            $intervalSec = null;
            if (isset($e['intervalSec']) && is_numeric($e['intervalSec'])) {
                $intervalSec = (int)$e['intervalSec'];
            } elseif (isset($e['interval'])) {
                $intervalSec = $this->IntervalStringToSeconds((string)$e['interval']);
            }

            // Content-Mode aus textType/attachmentType ableiten
            $contentMode = null;
            if (isset($e['textType']) || isset($e['attachmentType'])) {
                $text = isset($e['textType']) ? (int)$e['textType'] : 1;
                $att  = isset($e['attachmentType']) ? (int)$e['attachmentType'] : 0;
                if (!$text && $att === 1) $contentMode = 1;      // nur Bild
                elseif ($text && $att === 0) $contentMode = 0;   // nur Text
                elseif ($text && $att === 1) $contentMode = 2;   // Text+Bild
                elseif ($text && $att === 2) $contentMode = 3;   // Text+Video
                else $contentMode = 0;
            }

            return ['enabled'=>$enabled, 'intervalSec'=>$intervalSec, 'contentMode'=>$contentMode];
        }

        // LEGACY
        $res = $this->apiCall([[ "cmd"=>"GetEmail", "param"=>["channel"=>0] ]], 'EMAIL');
        if (!is_array($res) || !isset($res[0]['value']['Email'])) {
            $this->dbg('EMAIL', 'GetEmail: ungültige Antwort', $res);
            return null;
        }
        $e = $res[0]['value']['Email'];

        // enable
        $enabled = null;
        if (isset($e['schedule']['enable'])) {
            $enabled = ((int)$e['schedule']['enable'] === 1);
        }

        // interval (String → Sekunden)
        $intervalSec = null;
        if (isset($e['interval'])) {
            $intervalSec = $this->IntervalStringToSeconds((string)$e['interval']);
        }

        // attachment → contentMode
        $contentMode = null;
        if (isset($e['attachment'])) {
            switch ((string)$e['attachment']) {
                case 'onlyPicture': $contentMode = 1; break;
                case 'picture':     $contentMode = 2; break;
                case 'video':       $contentMode = 3; break;
                default:            $contentMode = 0; break;
            }
        }

        return ['enabled'=>$enabled, 'intervalSec'=>$intervalSec, 'contentMode'=>$contentMode];
    }


    private function EmailApply(?bool $enabled=null, ?int $intervalSec=null, ?int $contentMode=null): bool
    {
        $ver = $this->ApiVersion('email');
        $okSet = true;

        $wantSet = ($enabled !== null || $intervalSec !== null || $contentMode !== null);

        // --- SET ---
        if ($wantSet) {
            if ($ver === 'V20') {
                // Alles in EINEM SetEmailV20
                $email = [];
                if ($enabled !== null)      $email['enable'] = $enabled ? 1 : 0;
                if ($intervalSec !== null) {
                    $str = $this->IntervalSecondsToString($intervalSec);
                    if ($str !== null)      $email['interval'] = $str;
                }
                if ($contentMode !== null) {
                    switch ($contentMode) {
                        case 0: $email += ["textType"=>1, "attachmentType"=>0]; break;
                        case 1: $email += ["textType"=>0, "attachmentType"=>1]; break;
                        case 2: $email += ["textType"=>1, "attachmentType"=>1]; break;
                        case 3: $email += ["textType"=>1, "attachmentType"=>2]; break;
                    }
                }
                if (!empty($email)) {
                    $res = $this->apiCall([[ "cmd"=>"SetEmailV20", "param"=>["Email"=>$email] ]], 'EMAIL');
                    $okSet = is_array($res) && (($res[0]['code'] ?? -1) === 0);
                    if (!$okSet) $this->dbg('EMAIL', 'SetEmailV20 FAIL', $res);
                }
            } else {
                // LEGACY: Versuch alles in einem Rutsch, sonst Fallback einzeln
                $email = [];
                if ($enabled !== null)      $email['schedule']['enable'] = $enabled ? 1 : 0;
                if ($intervalSec !== null) {
                    $str = $this->IntervalSecondsToString($intervalSec);
                    if ($str !== null)      $email['interval'] = $str;
                }
                if ($contentMode !== null) {
                    $att = ['0','onlyPicture','picture','video'][$contentMode] ?? '0';
                    $email['attachment'] = $att;
                }

                if (!empty($email)) {
                    $res = $this->apiCall([[ "cmd"=>"SetEmail", "param"=>["Email"=>$email] ]], 'EMAIL', true);
                    $okSet = is_array($res) && (($res[0]['code'] ?? -1) === 0);
                    if (!$okSet) {
                        // Fallback: Feld für Feld
                        $okSet = true;
                        if ($enabled !== null) {
                            $r = $this->apiCall([[ "cmd"=>"SetEmail", "param"=>["Email"=>["schedule"=>["enable"=>$enabled?1:0]]] ]], 'EMAIL', true);
                            $okSet = $okSet && is_array($r) && (($r[0]['code'] ?? -1) === 0);
                        }
                        if ($intervalSec !== null) {
                            $str = $this->IntervalSecondsToString($intervalSec);
                            if ($str !== null) {
                                $r = $this->apiCall([[ "cmd"=>"SetEmail", "param"=>["Email"=>["interval"=>$str]] ]], 'EMAIL', true);
                                $okSet = $okSet && is_array($r) && (($r[0]['code'] ?? -1) === 0);
                            }
                        }
                        if ($contentMode !== null) {
                            $att = ['0','onlyPicture','picture','video'][$contentMode] ?? '0';
                            $r = $this->apiCall([[ "cmd"=>"SetEmail", "param"=>["Email"=>["attachment"=>$att]] ]], 'EMAIL', true);
                            $okSet = $okSet && is_array($r) && (($r[0]['code'] ?? -1) === 0);
                        }
                        if (!$okSet) $this->dbg('EMAIL', 'SetEmail (fallback) FAIL');
                    }
                }
            }
        }

        // --- GET (einmal) + Variablen setzen ---
        $state = $this->GetEmailState();
        if (is_array($state)) {
            $this->ApplyEmailStateToVars($state);
        } else {
            $this->dbg('EMAIL', 'GetEmailState FAIL (no data)');
        }

        // true = Set hat (insgesamt) funktioniert; bei reinem GET ist $okSet ohnehin true
        return $okSet;
    }

    // ---------------------------
    // PTZ / Zoom
    // ---------------------------
    private function CreateOrUpdatePTZHtml(bool $reloadPresets = false): void
    {
        if (!@$this->GetIDForIdent("PTZ_HTML")) {
            $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 8);
        }
        $hook = $this->ReadAttributeString("CurrentHook");
        if ($hook === "") {
            $hook = $this->RegisterHook();
        }

        // ---- Presets: aus Cache, nur bei Bedarf von der Kamera holen ----
        $presets = [];
        if (!$reloadPresets) {
            $cached = $this->ReadAttributeString("PtzPresetsCache");
            if (is_string($cached) && $cached !== "") {
                $tmp = @json_decode($cached, true);
                if (is_array($tmp)) $presets = $tmp;
            }
        }
        if (empty($presets)) {
            $presets = $this->getPresetList(); // <<< API-CALL NUR HIER (selten)
            $this->WriteAttributeString("PtzPresetsCache", json_encode($presets, JSON_UNESCAPED_UNICODE));
        }

        // ---- Zoom-Info: 1 schlanker Call pro Zyklus ----
        $zInfo = $this->getZoomInfo(); // <<< EIN API-CALL pro Refresh
        $zMin = is_array($zInfo) ? ($zInfo['min'] ?? 0) : 0;
        $zMax = is_array($zInfo) ? ($zInfo['max'] ?? 27) : 27;
        $zPos = is_array($zInfo) ? ($zInfo['pos'] ?? $zMin) : $zMin;

        // ---- UI rendern (wie zuvor) ----
        $rows = '';
        if (!empty($presets)) {
            foreach ($presets as $p) {
                $pid   = (int)$p['id'];
                $title = htmlspecialchars((string)$p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $rows .= '<div class="preset-row" data-preset="'.$pid.'">'.
                        '<button class="preset" data-preset="'.$pid.'" title="'.$title.'">'.$title.'</button>'.
                        '<div class="icons">'.
                        '<button class="icon rename" data-preset="'.$pid.'" title="Umbenennen" aria-label="Umbenennen">✎</button>'.
                        '<button class="icon del" data-preset="'.$pid.'" title="Löschen" aria-label="Löschen">🗑</button>'.
                        '</div>'.
                        '</div>';
            }
        } else {
            $rows = '<div class="no-presets">Keine Presets gefunden.</div>';
        }

        $btn = 42; $gap = 6;
        $html = <<<HTML
    <div id="ptz-wrap" style="font-family:system-ui,Segoe UI,Roboto,Arial; overflow:hidden;">
    <style>
    #ptz-wrap{ --btn: {$btn}px; --gap: {$gap}px; --fs: 16px; --radius: 10px; max-width: 520px; margin:0 auto; user-select:none; }
    #ptz-wrap .grid{ display:grid; grid-template-columns:repeat(3, var(--btn)); grid-template-rows:repeat(3, var(--btn)); gap:var(--gap); justify-content:center; align-items:center; margin-bottom:10px; }
    #ptz-wrap button{ height: var(--btn); border:1px solid #cfcfcf; border-radius:var(--radius); background:#f8f8f8; font-size:var(--fs); line-height:1; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.06); box-sizing:border-box; padding:6px 10px; }
    #ptz-wrap .dir{ width:var(--btn); padding:0; }
    #ptz-wrap button:hover{ filter:brightness(.98); }
    #ptz-wrap button:active{ transform:translateY(1px); }
    #ptz-wrap .up{grid-column:2;grid-row:1;} .left{grid-column:1;grid-row:2;} #ptz-wrap .right{grid-column:3;grid-row:2;} .down{grid-column:2;grid-row:3;}
    #ptz-wrap .section-title{ font-weight:600; margin:10px 0 6px; }
    #ptz-wrap .presets{ display:block; }
    #ptz-wrap .preset-row{ display:flex; align-items:center; gap:8px; margin-bottom:var(--gap); }
    #ptz-wrap .preset{ flex:1; height:auto; min-height:36px; padding:8px 12px; text-align:left; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    #ptz-wrap .icons { display:flex; gap:6px; }
    #ptz-wrap .icon{ width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; padding:0; font-size:18px; }
    #ptz-wrap .no-presets{ opacity:.7; padding:4px 0; }
    #ptz-wrap .new{ margin-top:10px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
    #ptz-wrap .new input[type="text"]{ flex:1; min-width:160px; height:34px; padding:4px 8px; border:1px solid #cfcfcf; border-radius:8px; }
    #ptz-wrap .new button{ height:36px; padding:6px 10px; }
    #ptz-wrap .hint{ font-size:14px; opacity:.75; }
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
    <div class="presets"> {$rows} </div>
    <div class="section-title">Neues Preset</div>
    <div class="new">
    <input type="text" id="ptz-new-name" maxlength="32" placeholder="Name eingeben …"/>
    <button id="ptz-new-save" title="Aktuelle Position als neues Preset speichern">Speichern</button>
    </div>
    <script>
    (function(){
    var base = "{$hook}";
    var wrap = document.getElementById("ptz-wrap");
    var msg  = document.getElementById("ptz-msg");
    var nameIn   = document.getElementById("ptz-new-name");

    function show(text, ok){
        if (!msg) return;
        msg.className = "status " + (ok ? "ok" : "err");
        msg.textContent = text;
    }
    function call(op, extra){
        var qs = new URLSearchParams(extra || {});
        qs.set("ptz", op);
        var url = base + "?" + qs.toString();
        return fetch(url, { method: "GET", credentials: "same-origin", cache: "no-store" })
        .then(function(r){ return r.text().catch(function(){ return ""; }); })
        .then(function(t){
            var body = (t || "").trim().toUpperCase();
            var ok = (body === "OK");
            if (ok) { show("OK", true); return true; }
            if ((t || "").trim() === "") { show("Gesendet", true); return true; }
            show("Fehler: " + (t || ""), false); return false;
        })
        .catch(function(){ show("Gesendet", true); return true; });
    }
    function calcNextId(){
        var rows = wrap.querySelectorAll(".preset-row[data-preset]");
        var max = -1;
        rows.forEach(function(el){
        var v = parseInt(el.getAttribute("data-preset") || "-1", 10);
        if (!isNaN(v) && v > max) max = v;
        });
        return max + 1;
    }

    var zoomEl = document.getElementById("ptz-zoom");
    if (zoomEl) {
        zoomEl.addEventListener("change", function(){
        var target = parseInt(zoomEl.value, 10);
        if (isNaN(target)) return;
        call("zoompos", { pos: target });
        });
    }

    wrap.addEventListener("click", function(ev){
        var btn = ev.target.closest("button");
        if (!btn) return;

        if (btn.hasAttribute("data-dir")) {
        call(btn.getAttribute("data-dir")); return;
        }
        if (btn.classList.contains("preset") && btn.hasAttribute("data-preset")) {
        call("preset:" + btn.getAttribute("data-preset")); return;
        }
        if (btn.classList.contains("rename") && btn.hasAttribute("data-preset")) {
        var id = parseInt(btn.getAttribute("data-preset") || "0", 10);
        var cur = (btn.parentElement && btn.parentElement.previousElementSibling) ? btn.parentElement.previousElementSibling.textContent.trim() : "";
        var neu = window.prompt("Neuer Name für Preset " + id + ":", cur);
        if (neu && neu.trim() !== "") { call("rename", { id:id, name:neu.trim() }); }
        return;
        }
        if (btn.classList.contains("del") && btn.hasAttribute("data-preset")) {
        var idd = parseInt(btn.getAttribute("data-preset") || "0", 10);
        if (window.confirm("Preset " + idd + " löschen?")) { call("delete", { id: idd }); }
        return;
        }
        if (btn.id === "ptz-new-save") {
        var nm = (nameIn.value || "").trim();
        if (!nm) { show("Bitte einen Namen eingeben.", false); return; }
        var nid = calcNextId();
        call("save", { id: nid, name: nm }).then(function(ok){ if (ok) { nameIn.value = ""; } });
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
        $id  = @$this->GetIDForIdent($ident);
        $old = ($id !== false) ? GetValue($id) : null;
        if (!is_string($old) || $old !== $html) {
            $this->SetValue($ident, $html);
        }
        else {
        $this->dbg('PTZ', 'Unverändert', ['ident' => $ident], true);
        }       
    }

    private function HandlePtzCommand(string $cmd): bool
    {
        $stepParam = isset($_REQUEST['step']) ? max(1, (int)$_REQUEST['step']) : 1;
        $idParam   = $_REQUEST['id']   ?? null;
        $nameParam = $_REQUEST['name'] ?? null;
        $id   = is_null($idParam)   ? null : (int)$idParam;
        $name = is_null($nameParam) ? null : (string)$nameParam;

        if (strpos($cmd, 'preset:') === 0) {
            $pid = (int)substr($cmd, 7);
            if ($pid >= 0) return $this->ptzGotoPreset($pid);
            $this->dbg("PTZ", "Ungueltige Preset-ID", $cmd);
            return false;
        }

        switch (strtolower($cmd)) {
            case 'save':
                if ($id === null || $id < 0) { $this->dbg("PTZ","id fehlt/ungueltig"); return false; }
                if (is_string($name)) {
                    $name = trim($name);
                    if ($name === '') $name = null;
                    if ($name !== null) {
                        $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $name);
                        $name = mb_substr($name, 0, 32, 'UTF-8');
                    }
                } else { $name = null; }
                $ok = $this->PTZ_SavePreset($id, $name);
                $this->dbg("PTZ", "SAVE", ['id'=>$id, 'name'=>$name, 'ok'=>$ok]);
                return $ok;

            case 'rename':
                if ($id === null || $id < 0 || !is_string($name) || trim($name) === '') { $this->dbg("PTZ","id/name fehlen"); return false; }
                $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', trim($name));
                $name = mb_substr($name, 0, 32, 'UTF-8');
                $ok = $this->PTZ_RenamePreset($id, $name);
                $this->dbg("PTZ", "RENAME", ['id'=>$id, 'name'=>$name, 'ok'=>$ok]);
                return $ok;

            case 'delete':
                if ($id === null || $id < 0) { $this->dbg("PTZ","id fehlt"); return false; }
                $ok = $this->PTZ_DeletePreset($id);
                $this->dbg("PTZ", "DELETE", ['id'=>$id, 'ok'=>$ok]);
                return $ok;

            case 'zoomin':  return $this->ptzZoom('in',  $stepParam);
            case 'zoomout': return $this->ptzZoom('out', $stepParam);

            case 'zoompos':
                if (!isset($_REQUEST['pos'])) { $this->dbg("PTZ","pos fehlt"); return false; }
                $pos = (int)$_REQUEST['pos'];
                $info = $this->getZoomInfo();
                if (is_array($info)) $pos = max($info['min'], min($info['max'], $pos));
                $ok = $this->setZoomPos($pos);
                $this->dbg("PTZ", "ZOOMPOS", ['pos'=>$pos, 'ok'=>$ok]);
                return $ok;
        }

        $map = [ 'left' => 'Left', 'right' => 'Right', 'up' => 'Up', 'down' => 'Down' ];
        $k = strtolower($cmd);
        if (!isset($map[$k])) {
            $this->dbg("PTZ", "Unbekanntes Kommando", $cmd);
            return false;
        }
        return $this->ptzCtrl($map[$k]);
    }

    private function getPtzStyle(): string
    {
        $s = $this->ReadAttributeString("PtzStyle");
        return ($s === "flat" || $s === "nested") ? $s : "";
    }
    private function setPtzStyle(string $s): void
    {
        if ($s === "flat" || $s === "nested") {
            $this->WriteAttributeString("PtzStyle", $s);
            $this->dbg("PTZ", "PtzStyle gesetzt", $s);
        }
    }

    private function postCmdDual(string $cmd, array $body, ?string $nestedKey=null, bool $suppress=false): ?array
    {
        $nestedKey = $nestedKey ?: $cmd;
        $known = $this->getPtzStyle();
        $order = $known ? [$known, ($known === 'flat' ? 'nested' : 'flat')] : ['flat','nested'];

        foreach ($order as $mode) {
            $payload = [[ 'cmd' => $cmd, 'param' => ($mode === 'flat') ? $body : [$nestedKey => $body] ]];
            $resp = $this->apiCall($payload, 'PTZ', /*suppress*/ true);
            if (is_array($resp) && (($resp[0]['code'] ?? -1) === 0)) {
                if ($known !== $mode) $this->setPtzStyle($mode);
                return $resp;
            }
        }
        if (!$suppress) {
            $this->dbg("PTZ", "postCmdDual FAIL", ['cmd'=>$cmd, 'body'=>$body]);
            $this->LogMessage("Reolink/PTZ: postCmdDual FAIL for {$cmd}", KL_ERROR);
        }
        return null;
    }

    private function ptzCtrl(string $op, array $extra = [], int $pulseMs = 250): bool
    {
        $param = ['channel' => 0, 'op' => $op] + $extra;
        $isMove = in_array($op, ['Left','Right','Up','Down'], true);
        if ($isMove && !isset($param['speed'])) {
            $param['speed'] = 5;
        }

        $ok = is_array($this->postCmdDual('PtzCtrl', $param, 'PtzCtrl', /*suppress*/ false));
        if (!$ok) return false;

        if ($isMove) {
            IPS_Sleep($pulseMs);
            $this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'Stop'], 'PtzCtrl', /*suppress*/ true);
        }
        return true;
    }

    private function ptzGotoPreset(int $id): bool
    {
        $ok = is_array($this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'ToPos',    'id'=>$id], 'PtzCtrl', /*suppress*/ true));
        if ($ok) return true;
        $ok = is_array($this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'ToPreset', 'id'=>$id], 'PtzCtrl', /*suppress*/ true));
        if ($ok) return true;
        $this->dbg('PTZ', "Preset anfahren fehlgeschlagen", $id);
        return false;
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

                $name = $p['name'] ?? $p['Name'] ?? $p['sName'] ?? $p['label'] ?? $p['presetName'] ?? '';
                $trim = trim((string)$name);

                $isGeneric = ($trim !== '') && (preg_match('/^(pos|preset|position)\s*0*\d+$/i', $trim) === 1);
                $flag  = $p['exist'] ?? $p['bExist'] ?? $p['bexistPos'] ?? $p['enable'] ?? $p['enabled'] ?? $p['set'] ?? $p['bSet'] ?? null;
                $isSet = ($flag === 1 || $flag === '1' || $flag === true);
                $posArr = $p['pos'] ?? $p['position'] ?? $p['ptzpos'] ?? $p['ptz'] ?? null;
                $hasPos = false;
                if (is_array($posArr)) {
                    foreach ($posArr as $v) {
                        if (is_numeric($v) && (float)$v != 0.0) { $hasPos = true; break; }
                    }
                }
                if (($trim === '' || $isGeneric) && !$isSet && !$hasPos) continue;
                if ($trim === '') $name = "Preset ".$id;

                $out[] = ['id'=>$id, 'name'=>$name];
                $seen[$id] = true;
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
                $this->dbg('PTZ', 'Keine Presets erkannt', $res);
            }
        } else {
            $this->dbg('PTZ', 'Kein gueltiges Array-Response');
        }
        usort($list, fn($a,$b) => $a['id'] <=> $b['id']);
        return $list;
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

    public function PTZ_SavePreset(int $id, ?string $name=null): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzSetPreset($id);
        if ($ok && $name) { $this->ptzRenamePreset($id, $name); }
        if ($ok) {
            $this->WriteAttributeString("PtzPresetsCache", "");   // Cache leeren
            $this->CreateOrUpdatePTZHtml(true);                   // Presets neu holen
        }
        return $ok;
    }

    public function PTZ_RenamePreset(int $id, string $name): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzRenamePreset($id, $name);
        if ($ok) {
            $this->WriteAttributeString("PtzPresetsCache", "");
            $this->CreateOrUpdatePTZHtml(true);
        }
        return $ok;
    }

    public function PTZ_DeletePreset(int $id): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzClearPreset($id);
        if ($ok) {
            $this->WriteAttributeString("PtzPresetsCache", "");
            $this->CreateOrUpdatePTZHtml(true);
        }
        return $ok;
    }

    // ---------------------------
    // PTZ Presets: Set/Rename/Clear
    // ---------------------------
    // 1) Anlegen/Speichern
    private function ptzSetPreset(int $id, ?string $nameForCreate=null): bool {
        $entry = ['id'=>$id, 'enable'=>1];
        if ($nameForCreate !== null && $nameForCreate !== '') {
            $n = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $nameForCreate);
            $entry['name'] = mb_substr($n, 0, 32, 'UTF-8');
        }
        // bevorzugt: nested unter PtzPreset → table[]
        $ok = is_array($this->postCmdDual(
            'SetPtzPreset',
            ['channel'=>0, 'table'=>[ $entry ]],
            'PtzPreset',  // <<< WICHTIG
            /*suppress*/ true
        ));
        // Fallback: flat (ohne table)
        if (!$ok) {
            $flat = ['channel'=>0, 'id'=>$id, 'enable'=>1] + (isset($entry['name'])?['name'=>$entry['name']]:[]);
            $ok = is_array($this->postCmdDual(
                'SetPtzPreset',
                $flat,
                'PtzPreset',  // <<< WICHTIG
                /*suppress*/ true
            ));
        }
        if (!$ok) $this->dbg('PTZ/SetPtzPreset', 'Fehlgeschlagen', $entry);
        return (bool)$ok;
    }

    // 2) Umbenennen
    private function ptzRenamePreset(int $id, string $name): bool {
        $name = trim($name);
        if ($name === '') return false;
        $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $name);
        $name = mb_substr($name, 0, 32, 'UTF-8');

        // bevorzugt: nested via table[]
        $ok = is_array($this->postCmdDual(
            'SetPtzPreset',
            ['channel'=>0, 'table'=>[ ['id'=>$id, 'name'=>$name] ]],
            'PtzPreset',  // <<< WICHTIG
            /*suppress*/ true
        ));
        if (!$ok) {
            // Fallback: flat
            $ok = is_array($this->postCmdDual(
                'SetPtzPreset',
                ['channel'=>0, 'id'=>$id, 'name'=>$name],
                'PtzPreset',  // <<< WICHTIG
                /*suppress*/ true
            ));
        }
        // letzte Fallbacks (einige ältere FWs)
        if (!$ok) {
            $ok = is_array($this->postCmdDual('PtzPreset', ['channel'=>0,'id'=>$id,'name'=>$name,'cmd'=>'SetName'], 'PtzPreset', true))
            ?: is_array($this->postCmdDual('PtzCtrl',   ['channel'=>0,'op'=>'SetPresetName','id'=>$id,'name'=>$name], 'PtzCtrl', true));
        }
        if (!$ok) $this->dbg('PTZ/Rename', 'Fehlgeschlagen', ['id'=>$id,'name'=>$name]);
        return (bool)$ok;
    }

    // 3) Löschen/Clear
    private function ptzClearPreset(int $id): bool {
        // robust: enable=0 + name='' über SetPtzPreset
        $ok = is_array($this->postCmdDual(
            'SetPtzPreset',
            ['channel'=>0, 'table'=>[ ['id'=>$id, 'enable'=>0, 'name'=>''] ]],
            'PtzPreset',   // <<< WICHTIG
            /*suppress*/ true
        ));
        if (!$ok) {
            $ok = is_array($this->postCmdDual(
                'SetPtzPreset',
                ['channel'=>0, 'id'=>$id, 'enable'=>0, 'name'=>''],
                'PtzPreset',   // <<< WICHTIG
                /*suppress*/ true
            ));
        }
        if (!$ok) $this->dbg('PTZ/Clear', 'enable=0 fehlgeschlagen', ['id'=>$id]);
        return (bool)$ok;
    }

    // ---------------------------
    // FTP EIN/AUS
    // ---------------------------

    private function UpdateFtpStatus(): void
    {
        $idVar = @$this->GetIDForIdent("FTPEnabled");
        if ($idVar === false) return;

        // V20 zuerst
        $res = $this->apiCall([[ "cmd"=>"GetFtpV20", "param"=>["channel"=>0] ]], 'FTP', true);
        if (is_array($res) && (($res[0]['code'] ?? -1) === 0)) {
            $ftp = $res[0]['value']['Ftp'] ?? null;
            if (is_array($ftp) && array_key_exists('enable', $ftp)) {
                $this->SetValue("FTPEnabled", ((int)$ftp['enable'] === 1));
            }
            return;
        }

        // Legacy Fallback (mit/ohne action)
        $res2 = $this->apiCall([[ "cmd"=>"GetFtp", "action"=>1, "param"=>["channel"=>0] ]], 'FTP', true);
        if (!(is_array($res2) && (($res2[0]['code'] ?? -1) === 0))) {
            $res2 = $this->apiCall([[ "cmd"=>"GetFtp", "param"=>["channel"=>0] ]], 'FTP', true);
        }
        if (is_array($res2) && (($res2[0]['code'] ?? -1) === 0)) {
            $node = $res2[0]['value']['Ftp'] ?? ($res2[0]['value'] ?? null);
            $enabled = null;
            if (isset($node['schedule']['enable'])) {
                $enabled = ((int)$node['schedule']['enable'] === 1);
            } elseif (isset($node['enable'])) {
                $enabled = ((int)$node['enable'] === 1);
            }
            if ($enabled !== null) {
                $this->SetValue("FTPEnabled", $enabled);
            }
        }
    }

    private function FtpApply(bool $on): bool
    {
        $ver = $this->ApiVersion('ftp');
        $ok  = false;

        if ($ver === "V20") {
            $r = $this->apiCall([[ "cmd"=>"SetFtpV20",
                "param"=>[ "Ftp" => [ "enable" => ($on ? 1 : 0), "channel" => 0 ] ]
            ]], 'FTP', true);
            $ok = is_array($r) && (($r[0]['code'] ?? -1) === 0);
        } elseif ($ver === "LEGACY" || $ver === "LEGACY_A1") {
            // zuerst Ftp.enable direkt
            $p = [[ "cmd"=>"SetFtp", "param"=>[ "Ftp" => [ "enable" => ($on ? 1 : 0), "channel" => 0 ] ] ]];
            if ($ver === "LEGACY_A1") $p[0]["action"] = 1;
            $r1 = $this->apiCall($p, 'FTP', true);
            $ok = is_array($r1) && (($r1[0]['code'] ?? -1) === 0);

            // Fallback: schedule.enable
            if (!$ok) {
                $p2 = [[ "cmd"=>"SetFtp", "param"=>[ "Ftp" => [ "schedule" => [ "enable" => ($on ? 1 : 0) ], "channel" => 0 ] ] ]];
                if ($ver === "LEGACY_A1") $p2[0]["action"] = 1;
                $r2 = $this->apiCall($p2, 'FTP', true);
                $ok = is_array($r2) && (($r2[0]['code'] ?? -1) === 0);
            }
        }

        if ($ok) {
            // UI-sync
            $this->UpdateFtpStatus();
        } else {
            $this->dbg('FTP', 'Setzen fehlgeschlagen – evtl. Firmware-Variante nicht unterstützt');
        }
        return $ok;
    }

    // ---------------------------
    // Sensitivity
    // ---------------------------

    private function GetMdSensitivity(): ?array
    {
        $ver = $this->ApiVersion('schedule');
        $cmd = ($ver === 'V20') ? 'GetMdAlarm' : 'GetAlarm';

        $res = $this->apiCall([[ "cmd"=>$cmd, "action"=>1, "param"=>["channel"=>0] ]], 'ALARM');
        if (!is_array($res) || ($res[0]['code']??1) !== 0) return null;

        $node = $this->apiGetNode($res, ($ver === 'V20') ? 'MdAlarm' : 'Alarm');
        if (!is_array($node)) return null;

        $sensDef = null;
        $segments = [];
        if (!empty($node['newSens'])) {
            $sensDef  = isset($node['newSens']['sensDef']) ? (int)$node['newSens']['sensDef'] : null;
            $segments = $this->mdNormalizeSegments($node['newSens']['sens'] ?? []);
        } elseif (!empty($node['sens'])) {
            $segments = $this->mdNormalizeSegments($node['sens']);
        }

        $active = $this->mdPickActiveNow($segments, $sensDef);

        return [
            'apiVer'   => $ver,
            'sensDef'  => $sensDef,
            'segments' => $segments,
            'active'   => $active
        ];
    }

    public function SetMdSensitivity(int $level): bool
    {
        // UI: 1..50
        $level = max(1, min(50, $level));
        // Kamera erwartet invertiert: 50..1
        $levelCam = 51 - $level;

        $state = $this->GetMdSensitivity();
        if ($state === null) return false;

        $ver      = $state['apiVer'];                    // 'V20'|'LEGACY'
        $paramKey = ($ver === 'V20') ? 'MdAlarm' : 'Alarm';
        $cmdSet   = ($ver === 'V20') ? 'SetMdAlarm' : 'SetAlarm';

        // vorhandene Segmente übernehmen, nur sensitivity angleichen (mit Kamera-Wert!)
        $segments = $state['segments'];
        if (empty($segments)) {
            $segments = [[ 'beginHour'=>0,'beginMin'=>0,'endHour'=>23,'endMin'=>59,'sensitivity'=>$levelCam ]];
        } else {
            foreach ($segments as &$s) { $s['sensitivity'] = $levelCam; }
            unset($s);
        }

        if ($ver === 'V20') {
            $payload = [[
                "cmd"   => $cmdSet,
                "param" => [
                    $paramKey => [
                        "type"       => "md",
                        "useNewSens" => 1,
                        "newSens"    => [
                            "sensDef" => $levelCam,   // ebenfalls invertiert setzen
                            "sens"    => $segments
                        ],
                        "channel"    => 0
                    ]
                ]
            ]];
            $res = $this->apiCall($payload, 'ALARM_SET');
            return (is_array($res) && ($res[0]['code'] ?? 1) === 0);
        }

        // LEGACY
        $payload = [[
            "cmd"   => $cmdSet,
            "param" => [
                $paramKey => [
                    "type"    => "md",
                    "sens"    => $segments,
                    "channel" => 0
                ]
            ]
        ]];
        $res = $this->apiCall($payload, 'ALARM_SET');
        return (is_array($res) && ($res[0]['code'] ?? 1) === 0);
    }

    private function UpdateMdSensitivityStatus(): void
    {
        $vid = @$this->GetIDForIdent("MdSensitivity");
        if ($vid === false) return;

        $st = $this->GetMdSensitivity();
        if (!$st) return;

        // Kamera liefert 1..50
        $lvlCam = max(1, min(50, (int)($st['active'] ?? 0)));

        // UI zeigt invertiert (50..1)
        $lvlUI = 51 - $lvlCam;

        if ((int)GetValue($vid) !== $lvlUI) {
            $this->SetValue("MdSensitivity", $lvlUI);
        }
    }

    private function mdNormalizeSegments($raw): array
    {
        $out = [];
        $push = function($a) use (&$out) {
            $out[] = [
                'beginHour'   => (int)($a['beginHour'] ?? 0),
                'beginMin'    => (int)($a['beginMin']  ?? 0),
                'endHour'     => (int)($a['endHour']   ?? 23),
                'endMin'      => (int)($a['endMin']    ?? 59),
                'sensitivity' => (int)($a['sensitivity'] ?? ($a['sens'] ?? 0))
            ];
        };
        $walk = function($node) use (&$walk, $push) {
            if (is_array($node)) {
                if (isset($node['beginHour']) || isset($node['beginMin']) || isset($node['endHour']) || isset($node['endMin'])) {
                    $push($node);
                } else {
                    foreach ($node as $v) $walk($v);
                }
            }
        };
        $walk($raw);

        // grobe Plausibilitätsprüfung
        $filtered = [];
        foreach ($out as $s) {
            $bh=$s['beginHour']; $bm=$s['beginMin']; $eh=$s['endHour']; $em=$s['endMin'];
            if ($bh<0||$bh>23||$eh<0||$eh>23||$bm<0||$bm>59||$em<0||$em>59) continue;
            if ($s['sensitivity'] < 1 || $s['sensitivity'] > 50) continue;
            $filtered[] = $s;
        }
        return array_values($filtered);
    }

    private function mdPickActiveNow(array $segments, ?int $sensDef): int
    {
        $now = (int)date('G')*60 + (int)date('i');
        foreach ($segments as $s) {
            $start = $s['beginHour']*60 + $s['beginMin'];
            $end   = $s['endHour']*60   + $s['endMin'];
            if ($start <= $end) {
                if ($now >= $start && $now <= $end) return (int)$s['sensitivity'];
            } else {
                if ($now >= $start || $now <= $end)  return (int)$s['sensitivity'];
            }
        }
        return (int)($sensDef ?? ($segments[0]['sensitivity'] ?? 10));
    }

    private function apiGetNode(array $resp, string $key)
    {
        // nimmt entweder value[$key] ODER initial[$key]
        $root = $resp[0] ?? [];
        $v = $root['value'][$key] ?? null;
        if ($v === null) $v = $root['initial'][$key] ?? null;
        return is_array($v) ? $v : null;
    }

    // ---------------------------
    // Sirene ein-aus
    // ---------------------------

    public function SetSirenEnabled(bool $on): bool
    {
        $isV20 = ($this->ApiVersion('audio') === 'V20');

        // Erst lesen, damit wir das Schedule-Table unverändert zurückschreiben
        $getCmd = $isV20 ? 'GetAudioAlarmV20' : 'GetAudioAlarm';
        $res = $this->apiCall([[ "cmd"=>$getCmd, "action"=>1, "param"=>["channel"=>0] ]], 'AUDIO_GET');
        if (!is_array($res) || ($res[0]['code']??1) !== 0) return false;
        $audio = $res[0]['value']['Audio'] ?? null;
        if (!is_array($audio)) return false;

        $audio['enable'] = $on ? 1 : 0; // nur Toggle!

        $setCmd = $isV20 ? 'SetAudioAlarmV20' : 'SetAudioAlarm';
        $payload = [ [ "cmd"=>$setCmd, "param"=>["Audio"=>$audio] ] ];
        $r2 = $this->apiCall($payload, 'AUDIO_SET');
        return (is_array($r2) && ($r2[0]['code']??1) === 0);
    }

    private function UpdateSirenStatus(): void
    {
        $vid = @$this->GetIDForIdent("SirenEnabled");
        if ($vid === false) return;

        $isV20 = ($this->ApiVersion('audio') === 'V20');
        $getCmd = $isV20 ? 'GetAudioAlarmV20' : 'GetAudioAlarm';

        $res = $this->apiCall([[ "cmd"=>$getCmd, "action"=>1, "param"=>["channel"=>0] ]], 'AUDIO', true);
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) return;

        $audio = $res[0]['value']['Audio'] ?? null;
        if (!is_array($audio) || !array_key_exists('enable', $audio)) return;

        $enabled = ((int)$audio['enable'] === 1);
        if ((bool)GetValue($vid) !== $enabled) {
            $this->SetValue("SirenEnabled", $enabled);
        }
    }
}
