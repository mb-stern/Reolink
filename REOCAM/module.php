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

        // Webhook registrieren
        $this->RegisterHook('/hook/reolink');

        // Medienobjekte für Stream und Snapshot erstellen
        $this->RegisterMediaObjects();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');

        // URLs für Medienobjekte aktualisieren
        $this->UpdateMediaObjects();
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
                if ($hook['Hook'] == $Hook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        $found = true;
                        break;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
            }
            IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($hookInstanceID);
        }
    }

    private function RegisterMediaObjects()
    {
        // Medienobjekte für Stream und Snapshot erstellen
        $this->RegisterMediaObject("StreamURL", "Kamera Stream", 3, $this->GetStreamURL());
        $this->RegisterMediaObject("Snapshot", "Kamera Snapshot", 3, $this->GetSnapshotURL());
    }

    private function RegisterMediaObject($ident, $name, $type, $url)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia($type); // 3 steht für Stream oder Snapshot als URL
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetMediaFile($mediaID, $url, false); // URL als Datei für das Medienobjekt setzen
            IPS_SetMediaCached($mediaID, true); // Medienelement cachen, um wiederholte Anfragen zu vermeiden
        }
    }

    private function UpdateMediaObjects()
    {
        // URLs für die Medienobjekte aktualisieren
        $this->UpdateMediaObject("StreamURL", $this->GetStreamURL());
        $this->UpdateMediaObject("Snapshot", $this->GetSnapshotURL());
    }

    private function UpdateMediaObject($ident, $url)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($mediaID !== false) {
            IPS_SetMediaFile($mediaID, $url, false); // Aktualisiert die URL des Medienobjekts
        }
    }

    public function GetStreamURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");

        return "rtsp://$username:$password@$cameraIP:554//Preview_01_sub";
    }

    public function GetSnapshotURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");

        return "http://$cameraIP/cgi-bin/api.cgi?cmd=Snap&user=$username&password=$password";
    }
}

?>
