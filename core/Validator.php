<?php
namespace Core;

class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        
        foreach ($rules as $field => $ruleset) {
            $rulesArray = explode('|', $ruleset);
            
            foreach ($rulesArray as $rule) {
                if ($rule === 'nullable' && ( !isset($data[$field]) || $data[$field] === '' || $data[$field] === null)) {
                    continue 2;
                }

                if ($rule === 'required' && empty($data[$field])) {
                    $errors[$field][] = "El campo {$field} es requerido";
                    continue;
                }

                if ($rule === 'string' && isset($data[$field])) {
                    if (!is_string($data[$field])) {
                        $errors[$field][] = "El campo {$field} debe ser texto";
                    }
                }

                if (str_starts_with($rule, 'min:') && isset($data[$field])) {
                    $min = (int)substr($rule, 4);
                    if (strlen($data[$field]) < $min) {
                        $errors[$field][] = "El campo {$field} debe tener al menos {$min} caracteres";
                    }
                }
                
                if (str_starts_with($rule, 'max:') && isset($data[$field])) {
                    $max = (int)substr($rule, 4);
                    if (strlen($data[$field]) > $max) {
                        $errors[$field][] = "El campo {$field} no puede tener más de {$max} caracteres";
                    }
                }
                
                if ($rule === 'email' && isset($data[$field])) {
                    if (!filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
                        $errors[$field][] = "El campo {$field} debe ser un email válido";
                    }
                }
                
                if ($rule === 'numeric' && isset($data[$field])) {
                    if (!is_numeric($data[$field])) {
                        $errors[$field][] = "El campo {$field} debe ser numérico";
                    }
                }

                if ($rule === 'integer' && isset($data[$field])) {
                    if (filter_var($data[$field], FILTER_VALIDATE_INT) === false) {
                        $errors[$field][] = "El campo {$field} debe ser un entero";
                    }
                }

                if ($rule === 'alpha' && isset($data[$field])) {
                    if (!ctype_alpha($data[$field])) {
                        $errors[$field][] = "El campo {$field} solo debe contener letras";
                    }
                }

                if ($rule === 'date' && isset($data[$field])) {
                    $value = (string)$data[$field];
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $errors[$field][] = "El campo {$field} debe tener formato YYYY-MM-DD";
                        continue;
                    }
                    [$year, $month, $day] = array_map('intval', explode('-', $value));
                    if (!checkdate($month, $day, $year)) {
                        $errors[$field][] = "El campo {$field} debe ser una fecha valida";
                    }
                }

                if (str_starts_with($rule, 'min_value:') && isset($data[$field])) {
                    $min = (float)substr($rule, 10);
                    if (!is_numeric($data[$field]) || (float)$data[$field] < $min) {
                        $errors[$field][] = "El campo {$field} debe ser mayor o igual a {$min}";
                    }
                }

                if (str_starts_with($rule, 'max_value:') && isset($data[$field])) {
                    $max = (float)substr($rule, 10);
                    if (!is_numeric($data[$field]) || (float)$data[$field] > $max) {
                        $errors[$field][] = "El campo {$field} debe ser menor o igual a {$max}";
                    }
                }
            }
        }

        return $errors;
    }
}
