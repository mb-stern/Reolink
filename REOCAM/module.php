<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Webhook registrieren
        $this->RegisterHook('/hook/reolink');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Webhook erneut registrieren, um sicherzustellen, dass er aktiv ist
        $this->RegisterHook('/hook/reolink');
    }

    private function RegisterHook($Hook)
    {
        // WebHook Control Modul-ID verwenden
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

            $this->SendDebug("RegisterHook", "Webhook erfolgreich registriert: " . $Hook, 0);
        } else {
            $this->SendDebug("RegisterHook", "WebHook Control Modul nicht gefunden!", 0);
        }
    }

    public function ProcessHookData()
    {
        $this->SendDebug('Webhook Triggered', 'Reolink Webhook wurde ausgelöst', 0);
        $data = file_get_contents('php://input');
        $this->SendDebug('Data Received', $data, 0);

        // Hier können Sie die erhaltenen Daten verarbeiten und in Variablen speichern
        IPS_LogMessage('Reolink', $data);
    }

    // Funktion für IPS_RequestAction
    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'UpdateValues') {
            $this->UpdateValues();
        }
    }

    // Methode zum manuellen Aktualisieren der Werte
    public function UpdateValues()
    {
        $this->SendDebug("UpdateValues", "Werte werden aktualisiert und Webhook wird geprüft", 0);

        // Webhook neu registrieren, falls er nicht existiert
        $this->RegisterHook('/hook/reolink');

        IPS_LogMessage("Reolink Update", "Werte wurden manuell aktualisiert");
        
        // Hier den Aktualisierungscode einfügen
    }
}

?>
