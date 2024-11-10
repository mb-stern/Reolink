<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        // Diese Zeile darf nicht entfernt werden
        parent::Create();

        // Webhook registrieren
        $this->RegisterHook("/hook/reolink");
    }

    public function ApplyChanges()
    {
        // Diese Zeile darf nicht entfernt werden
        parent::ApplyChanges();

        // Webhook erneut registrieren, um sicherzustellen, dass er aktiv ist
        $this->RegisterHook("/hook/reolink");
    }

    private function RegisterHook($Hook)
    {
        // ID des WebHook Control-Moduls
        $webhookID = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
        if (count($webhookID) > 0) {
            $hookInstanceID = $webhookID[0];

            // Hooks-Liste abrufen und prüfen, ob der Hook bereits existiert
            $hooks = json_decode(IPS_GetProperty($hookInstanceID, "Hooks"), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $Hook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        // Wenn der Hook bereits vorhanden und korrekt verlinkt ist, nichts tun
                        return;
                    }
                    // Wenn der Hook vorhanden ist, aber eine andere Instanz verlinkt, aktualisieren
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }

            // Wenn der Hook noch nicht existiert, hinzufügen
            if (!$found) {
                $hooks[] = [
                    "Hook" => $Hook,
                    "TargetID" => $this->InstanceID
                ];
            }

            // Aktualisierte Hooks-Liste im WebHook Control-Modul speichern
            IPS_SetProperty($hookInstanceID, "Hooks", json_encode($hooks));
            IPS_ApplyChanges($hookInstanceID);
        } else {
            IPS_LogMessage("Reolink", "WebHook Control Instance not found!");
        }
    }

    // Diese Methode wird aufgerufen, wenn der Webhook ausgelöst wird
    public function ProcessHookData()
    {
        $this->SendDebug("Webhook Triggered", "Reolink Webhook wurde ausgelöst", 0);
        $data = file_get_contents('php://input');
        $this->SendDebug("Data Received", $data, 0);

        // Die erhaltenen Daten im Log speichern
        IPS_LogMessage("Reolink", $data);
    }
}

?>
