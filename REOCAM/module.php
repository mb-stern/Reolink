<?php

class ReolinkWebhook extends IPSModule
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
        $id = @IPS_GetObjectIDByIdent("WebHook", 0);
        if ($id === false) {
            $id = IPS_CreateScript(0);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, "WebHook");
            IPS_SetName($id, "WebHook");
            IPS_SetScriptContent($id, "<?php ReolinkWebhook_HookHandler(\$_IPS['INSTANCE']);");
        }

        $webHookPath = "/hook/reolink";
        if (IPS_GetProperty($id, 'TargetID') !== $webHookPath) {
            IPS_SetProperty($id, 'TargetID', $webHookPath);
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        IPS_LogMessage("ReolinkWebhook", print_r($data, true));
        // Hier kann Logik hinzugefügt werden, die auf die Webhook-Daten reagiert.
    }

    public function HookHandler()
    {
        $this->SendDebug("Webhook Triggered", "Reolink Webhook wurde ausgelöst", 0);
        $data = file_get_contents('php://input');
        $this->SendDebug("Data Received", $data, 0);

        // Hier können Sie die erhaltenen Daten verarbeiten und in Variablen speichern.
        IPS_LogMessage("ReolinkWebhook", $data);
    }
}

?>
