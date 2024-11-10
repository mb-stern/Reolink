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
        // Überprüfen, ob das WebHook Control-Modul vorhanden ist
        $webhookControlID = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
        
        if (count($webhookControlID) > 0) {
            $hookInstanceID = $webhookControlID[0];

            // Prüfen, ob ein Skript für diesen Hook existiert
            $scriptID = @IPS_GetObjectIDByIdent("ReolinkHookHandler", $this->InstanceID);
            if ($scriptID === false) {
                // Neues Skript für den Webhook erstellen
                $scriptID = IPS_CreateScript(0); // 0 = PHP-Skript
                IPS_SetParent($scriptID, $this->InstanceID);
                IPS_SetIdent($scriptID, "ReolinkHookHandler");
                IPS_SetName($scriptID, "Reolink Webhook Handler");

                // Skriptinhalt festlegen
                $scriptContent = '<?php Reolink_HookHandler($_IPS["TARGET"]);';
                IPS_SetScriptContent($scriptID, $scriptContent);
            }

            // Webhook im WebHook Control-Modul registrieren
            IPS_SetProperty($hookInstanceID, "Hook", $Hook);
            IPS_SetProperty($hookInstanceID, "TargetID", $scriptID);
            IPS_ApplyChanges($hookInstanceID);
        }
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
