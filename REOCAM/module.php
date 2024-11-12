<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        parent::Create();
        
        // IP-Adresse, Benutzername und Passwort als Eigenschaften registrieren
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StreamType", "sub");

        // Schalter zum Anzeigen der Variablen
        $this->RegisterPropertyBoolean("ShowWebhookVariables", true);
        $this->RegisterPropertyBoolean("ShowBooleanVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);

        // Initiale Registrierung der Boolean-Variablen und Timer
        $this->RegisterBooleanVariables();
        $this->RegisterResetTimers();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Hook erneut registrieren
        $this->RegisterHook('/hook/reolink');

        // Boolean-Variablen und Timer verwalten
        if ($this->ReadPropertyBoolean("ShowBooleanVariables")) {
            $this->RegisterBooleanVariables();
            $this->RegisterResetTimers();
        } else {
            $this->UnregisterBooleanVariables();
        }

        // Webhook-Variablen je nach Schalter anzeigen oder löschen
        if ($this->ReadPropertyBoolean("ShowWebhookVariables")) {
            $this->CreateWebhookVariables();
        } else {
            $this->RemoveWebhookVariables();
        }

        // Snapshots je nach Einstellung anzeigen oder löschen
        if ($this->ReadPropertyBoolean("ShowSnapshots")) {
            $this->CreateOrUpdateSnapshots();
        } else {
            $this->RemoveSnapshots();
        }

        // Stream-URL aktualisieren
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");
    }

    private function RegisterHook($Hook)
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hookInstanceID = $ids[0];
            $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);
            if (!is_array($hooks)) {
                $hooks = [];
            }
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $Hook && $hook['TargetID'] == $this->InstanceID) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
                IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($hookInstanceID);
            }
        }
    }

    public function ProcessHookData()
    {
        $rawData = file_get_contents("php://input");
        $this->SendDebug('Webhook Triggered', 'Reolink Webhook wurde ausgelöst', 0);

        if (!empty($rawData)) {
            $data = json_decode($rawData, true);
            if (is_array($data)) {
                $this->ProcessData($data);
            }
        }
    }

    private function ProcessData($data)
    {
        // Aktuellen Alarmtyp setzen
        if (isset($data['alarm']['type'])) {
            $this->SetValue("type", $data['alarm']['type']);
        }

        // Boolean-Variablen sofort schalten und dann Schnappschüsse erstellen
        if ($this->ReadPropertyBoolean("ShowBooleanVariables")) {
            $this->ActivateBooleans($data['alarm']['type']);
        }

        // Webhook-Variablen aktualisieren
        if ($this->ReadPropertyBoolean("ShowWebhookVariables")) {
            foreach ($data['alarm'] as $key => $value) {
                $this->updateVariable($key, $value);
            }
        }
    }

    private function RegisterBooleanVariables()
    {
        $this->RegisterVariableBoolean("Person", "Person erkannt", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier erkannt", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug erkannt", "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);
    }

    private function UnregisterBooleanVariables()
    {
        $booleanVariables = ["Person", "Tier", "Fahrzeug", "Bewegung"];
        foreach ($booleanVariables as $var) {
            $this->UnregisterVariable($var);
            $this->DeleteTimerIfExists($var . "_Reset");
        }
    }

    private function ActivateBooleans($type)
    {
        $boolMap = [
            "PEOPLE" => "Person",
            "ANIMAL" => "Tier",
            "VEHICLE" => "Fahrzeug",
            "MD" => "Bewegung"
        ];

        if (array_key_exists($type, $boolMap)) {
            $this->SetValue($boolMap[$type], true);
            $this->SetTimer($boolMap[$type] . "_Reset", 5);
        }
    }

    private function RegisterResetTimers()
    {
        $booleanVariables = ["Person", "Tier", "Fahrzeug", "Bewegung"];
        foreach ($booleanVariables as $var) {
            $this->RegisterTimer($var . "_Reset", 0, 'IPS_SetValue($_IPS["TARGET"], false);');
        }
    }

    private function SetTimer($timerName, $seconds)
    {
        if ($this->ReadPropertyBoolean("ShowBooleanVariables")) {
            $this->SetTimerInterval($timerName, $seconds * 1000);
        }
    }

    private function CreateWebhookVariables()
    {
        $this->RegisterVariableString("type", "Alarm Typ", "", 15);
    }

    private function RemoveWebhookVariables()
    {
        $this->UnregisterVariable("type");
    }

    private function CreateOrUpdateSnapshots()
    {
        $snapshots = ["Person", "Tier", "Fahrzeug", "Bewegung"];
        foreach ($snapshots as $snapshot) {
            $this->CreateSnapshotAtPosition($snapshot, $this->GetPositionForBoolean($snapshot) + 1);
        }
    }

    private function RemoveSnapshots()
    {
        $snapshots = ["Snapshot_Person", "Snapshot_Tier", "Snapshot_Fahrzeug", "Snapshot_Bewegung"];
        foreach ($snapshots as $snapshotIdent) {
            $this->UnregisterVariable($snapshotIdent);
        }
    }

    private function updateVariable($name, $value)
    {
        $ident = $this->normalizeIdent($name);

        // Variablentyp bestimmen und registrieren
        if (is_string($value)) {
            $this->RegisterVariableString($ident, $name);
            $this->SetValue($ident, $value);
        } elseif (is_int($value)) {
            $this->RegisterVariableInteger($ident, $name);
            $this->SetValue($ident, $value);
        } elseif (is_float($value)) {
            $this->RegisterVariableFloat($ident, $name);
            $this->SetValue($ident, $value);
        } elseif (is_bool($value)) {
            $this->RegisterVariableBoolean($ident, $name);
            $this->SetValue($ident, $value);
        }
    }

    private function normalizeIdent($name)
    {
        $ident = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        return substr($ident, 0, 32);
    }
}

?>
