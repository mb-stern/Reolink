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
        // WebHook Control Modul-ID
        $webhookID = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
        
        if (count($webhookID) > 0) {
            $hookInstanceID = $webhookID[0];

            // Prüfen, ob ein Skript für diesen Hook existiert
            $hookScriptID = @IPS_GetObjectIDByIdent("ReolinkHookScript", $this->InstanceID);
            if ($hookScriptID === false) {
                // Neues Skript erstellen
                $hookScriptID = IPS_CreateScript(0); // 0 = PHP-Skript
                IPS_SetParent($hookScriptID, $this->InstanceID);
                IPS_SetIdent($hookScriptID, "ReolinkHookScript");
                IPS_SetName($hookScriptID, "Reolink Webhook Handler");
                
                // Skriptinhalt festlegen
                $scriptContent = '<?php Reolink_HookHandler($_IPS["INSTANCE"]);';
                IPS_SetScriptContent($hookScriptID, $scriptContent);
            }

            // Webhook im WebHook Control registrieren
            IPS_SetProperty($hookInstanceID, "WebHook", $Hook);
            IPS_ApplyChanges($hookInstanceID);
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
