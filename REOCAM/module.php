<?php

class Reolink extends IPSModule 
{
    
    public function Create() 
    {
        parent::Create();
        
        // Register properties
        $this->RegisterPropertyString('WebhookName', 'REOLINK');
        $this->RegisterPropertyString('SavePath', '/user/');
        $this->RegisterPropertyString('UserName', 'user');
        $this->RegisterPropertyString('Password', 'password');
        $this->RegisterHook($this->ReadPropertyString('WebhookName'));
    }

        public function ApplyChanges() {
        parent::ApplyChanges();
        $this->RegisterHook($this->ReadPropertyString('WebhookName'));
    }

    private function RegisterHook($WebHook)
    {
        // Hilfsfunktion zum Erstellen einer Variable, falls sie nicht existiert
        function createVariableIfNotExists($parentID, $name, $type) {
            $variableID = @IPS_GetObjectIDByIdent($name, $parentID);
            if ($variableID === false) {
                $variableID = IPS_CreateVariable($type);
                IPS_SetName($variableID, $name);
                IPS_SetIdent($variableID, $name);
                IPS_SetParent($variableID, $parentID);
            }
            return $variableID;
        }
        
        // Hilfsfunktion zum Setzen einer Variablen basierend auf ihrem Typ
        function setVariable($parentID, $key, $value) {
            if (is_string($value)) {
                $varID = createVariableIfNotExists($parentID, $key, 3); // String
                SetValue($varID, $value);
            } elseif (is_int($value)) {
                $varID = createVariableIfNotExists($parentID, $key, 1); // Integer
                SetValue($varID, $value);
            } elseif (is_float($value)) {
                $varID = createVariableIfNotExists($parentID, $key, 2); // Float
                SetValue($varID, $value);
            } elseif (is_bool($value)) {
                $varID = createVariableIfNotExists($parentID, $key, 0); // Boolean
                SetValue($varID, $value);
            } elseif (is_array($value) || is_object($value)) {
                $varID = createVariableIfNotExists($parentID, $key, 3); // String für JSON-String
                SetValue($varID, json_encode($value));
            } else {
                // Unbekannter Typ, als String speichern
                $varID = createVariableIfNotExists($parentID, $key, 3);
                SetValue($varID, (string)$value);
            }
        }
        
        // ID des aktuellen Skripts
        $scriptID = $_IPS['SELF'];
        
        // Erstellen oder Abrufen einer Kategorie unterhalb des Skripts
        $categoryID = @IPS_GetObjectIDByIdent('WebhookData', $scriptID);
        if ($categoryID === false) {
            $categoryID = IPS_CreateCategory(); // Kategorie erstellen
            IPS_SetName($categoryID, "Webhook Data");
            IPS_SetIdent($categoryID, 'WebhookData');
            IPS_SetParent($categoryID, $scriptID);
        }
        
        // Erfassen der Rohdaten
        $rawData = file_get_contents("php://input");
        
        if (!empty($rawData)) {
            $data = json_decode($rawData, true);
        
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    // Wenn der Wert selbst ein Array oder Objekt ist, weiter rekursiv durchlaufen
                    if (is_array($value) || is_object($value)) {
                        foreach ($value as $subKey => $subValue) {
                            if ($subKey === 'alarmTime') {
                                // Zeitstempel in richtige Zeitzone konvertieren und speichern
                                $dateTime = new DateTime($subValue);
                                $dateTime->setTimezone(new DateTimeZone('Europe/Berlin')); // Beispiel: Zeitzone auf Europa/Berlin setzen
                                $formattedAlarmTime = $dateTime->format('Y-m-d H:i:s');
                                setVariable($categoryID, $subKey, $formattedAlarmTime);
                            } else {
                                setVariable($categoryID, $subKey, $subValue);
                            }
                        }
                    } else {
                        setVariable($categoryID, $key, $value);
                    }
                }
        
                // Beispielhafte Protokollierung der Daten
                //IPS_LogMessage("Reolink-Carport", "Empfangene Daten: " . print_r($data, true));
            }
        } else {
            IPS_LogMessage("WebHook RAW", "Keine Rohdaten empfangen oder roher Datenstrom ist leer.");
        }
        
        echo "Message: " . (isset($_GET['Message']) ? $_GET['Message'] : "Keine Nachricht");  
    }
}