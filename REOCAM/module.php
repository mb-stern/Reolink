<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        // Diese Zeile darf nicht entfernt werden.
        parent::Create();

        // Webhook erstellen
        $this->RegisterHook("/hook/reolink");
    }

    public function ApplyChanges()
    {
        // Diese Zeile darf nicht entfernt werden.
        parent::ApplyChanges();
    }

    private function RegisterHook($Hook)
    {
        $webhookID = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
        
        if (count($webhookID) > 0) {
            $hookInstanceID = $webhookID[0];
            $hooks = IPS_GetProperty($hookInstanceID, "HookList");
            $found = false;

            // Prüfen, ob der Hook bereits existiert
            foreach (json_decode($hooks, true) as $hook) {
                if ($hook['Hook'] == $Hook) {
                    $found = true;
                    break;
                }
            }

            // Hook hinzufügen, wenn er nicht existiert
            if (!$found) {
                $hooks[] = [
                    "Hook" => $Hook,
                    "TargetID" => $this->InstanceID
                ];
                IPS_SetProperty($hookInstanceID, "HookList", json_encode($hooks));
                IPS_ApplyChanges($hookInstanceID);
            }
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        IPS_LogMessage("Reolink", print_r($data, true));
        // Hier kann Logik hinzugefügt werden, die auf die Webhook-Daten reagiert.
    }

    public function HookHandler()
    {
        $this->SendDebug("Webhook Triggered", "Reolink Webhook wurde ausgelöst", 0);
        $data = file_get_contents('php://input');
        $this->SendDebug("Data Received", $data, 0);

        // Hier können Sie die erhaltenen Daten verarbeiten und in Variablen speichern.
        IPS_LogMessage("Reolink", $data);
    }
}

?>
