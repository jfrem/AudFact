#!/bin/bash
set -e -E

GEMINI_API_KEY="$GEMINI_API_KEY"
MODEL_ID="gemini-3-flash-preview"
GENERATE_CONTENT_API="streamGenerateContent"

cat << EOF > request.json
{
    "contents": [
      {
        "role": "user",
        "parts": [
          {
            "inlineData": {
              "mimeType": "application/pdf",
              "data": "JVBERi0xLjQKJeLjz9MKNCAw..."
            }
          },
          {
            "text": "Documento objetivo: FORMULA MEDICA.\\nExtrae solo la información visible en este documento.\\nNo completes campos con inferencias desde otros documentos.\\nChecks visuales esperados:\\n- FirmaPrescriptor: Firma del médico/profesional que prescribe\\nArticulos efectivamente dispensados al paciente (Registro de Dispensación):\\n- GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5\\nEn `items`, extrae UNICAMENTE los articulos que coincidan (exactos u homologos) con la lista anterior.\\nSi un articulo prescrito no aparece en la lista de dispensados, omitelo de `items`.\\nIncluye los nombres tal como aparecen en el documento, no los de la lista.\\nInvoca exactamente una vez la función extract_document_data.\n\n"
          }
        ]
      },
      {
        "role": "model",
        "parts": [
          {
            "function_call": {
              "name": "assess_document_quality",
              "args": {"document_quality":"legible","quality_notes":["El documento es una fórmula médica clara con datos de paciente, médico y medicamentos."]}
            }
          }
        ]
      },
      {
        "role": "user",
        "parts": [
          {
            "function_response": {
              "name": "assess_document_quality",
              "response": {
                "output": ""
              }
            }
          }
        ]
      },
      {
        "role": "model",
        "parts": [
          {
            "function_call": {
              "name": "extract_fields",
              "args": {"DocumentoPaciente":"12132213","IPS":"I.P.S. CENTRO DE DIAGNOSTICO OCUPACIONAL","TipoDocumentoPaciente":"CC","FechaFormula":"2025-05-20","Medico":"CARLOS ESNEIDER MURCIA ROJAS","CodigoDiagnostico":null,"NombrePaciente":"ABSALON GARCIA"}
            }
          }
        ]
      },
      {
        "role": "user",
        "parts": [
          {
            "function_response": {
              "name": "extract_fields",
              "response": {
                "output": ""
              }
            }
          }
        ]
      },
      {
        "role": "model",
        "parts": [
          {
            "function_call": {
              "name": "extract_items",
              "args": {"items":[{"CantidadPrescrita":300,"NombreArticulo":"GASAS ESTERILES PRECORTADAS DE 10 POR 10 CM SOBRE POR 5 GASAS CAJA POR 100 UNIDADES","NumeroAutorizacion":"45082636"}]}
            }
          }
        ]
      },
      {
        "role": "user",
        "parts": [
          {
            "function_response": {
              "name": "extract_items",
              "response": {
                "output": ""
              }
            }
          }
        ]
      },
      {
        "role": "model",
        "parts": [
          {
            "function_call": {
              "name": "detect_visual_checks",
              "args": {"visual_checks":[{"presente":true,"check":"FirmaPrescriptor","detalle":"Firma manuscrita y sello del médico Carlos Esneider Murcia Rojas visibles al final del documento.","severidad":"none"}]}
            }
          }
        ]
      },
      {
        "role": "user",
        "parts": [
          {
            "function_response": {
              "name": "detect_visual_checks",
              "response": {
                "output": ""
              }
            }
          },
          {
            "text": "INSERT_INPUT_HERE"
          }
        ]
      }
    ],
    "generationConfig": {
      "maxOutputTokens": 4096,
      "thinkingConfig": {
        "thinkingLevel": "MINIMAL"
      },
      "mediaResolution": "MEDIA_RESOLUTION_HIGH"
    },
    "tools": [
      {
        "functionDeclarations": [
          {
            "name": "extract_fields",
            "description": "Extrae datos administrativos del paciente y la fórmula médica.",
            "parameters": {
              "type": "object",
              "properties": {
                "TipoDocumentoPaciente": {
                  "type": "string"
                },
                "DocumentoPaciente": {
                  "type": "string"
                },
                "NombrePaciente": {
                  "type": "string"
                },
                "Medico": {
                  "type": "string"
                },
                "IPS": {
                  "type": "string"
                },
                "FechaFormula": {
                  "type": "string"
                },
                "CodigoDiagnostico": {
                  "type": "string"
                }
              },
              "required": [
                "TipoDocumentoPaciente",
                "DocumentoPaciente",
                "NombrePaciente",
                "Medico",
                "IPS",
                "FechaFormula",
                "CodigoDiagnostico"
              ],
              "propertyOrdering": [
                "TipoDocumentoPaciente",
                "DocumentoPaciente",
                "NombrePaciente",
                "Medico",
                "IPS",
                "FechaFormula",
                "CodigoDiagnostico"
              ]
            }
          },
          {
            "name": "extract_items",
            "description": "Extrae artículos médicos prescritos y sus cantidades con validación de coincidencia.",
            "parameters": {
              "type": "object",
              "properties": {
                "items": {
                  "type": "array",
                  "items": {
                    "type": "object",
                    "properties": {
                      "NombreArticulo": {
                        "type": "string"
                      },
                      "CantidadPrescrita": {
                        "type": "number"
                      },
                      "NumeroAutorizacion": {
                        "type": "string"
                      }
                    },
                    "required": [
                      "NombreArticulo",
                      "CantidadPrescrita"
                    ],
                    "propertyOrdering": [
                      "NombreArticulo",
                      "CantidadPrescrita",
                      "NumeroAutorizacion"
                    ]
                  }
                }
              },
              "required": [
                "items"
              ],
              "propertyOrdering": [
                "items"
              ]
            }
          },
          {
            "name": "detect_visual_checks",
            "description": "Detecta elementos visuales como firma del prescriptor y sellos médicos.",
            "parameters": {
              "type": "object",
              "properties": {
                "visual_checks": {
                  "type": "array",
                  "items": {
                    "type": "object",
                    "properties": {
                      "check": {
                        "type": "string"
                      },
                      "presente": {
                        "type": "boolean"
                      },
                      "detalle": {
                        "type": "string"
                      },
                      "severidad": {
                        "type": "string"
                      }
                    },
                    "required": [
                      "check",
                      "presente",
                      "detalle",
                      "severidad"
                    ],
                    "propertyOrdering": [
                      "check",
                      "presente",
                      "detalle",
                      "severidad"
                    ]
                  }
                }
              },
              "required": [
                "visual_checks"
              ],
              "propertyOrdering": [
                "visual_checks"
              ]
            }
          },
          {
            "name": "assess_document_quality",
            "description": "Evalúa la calidad del documento médico para auditoría farmacéutica.",
            "parameters": {
              "type": "object",
              "properties": {
                "document_quality": {
                  "type": "string",
                  "enum": [
                    "legible",
                    "parcialmente_legible",
                    "ilegible"
                  ]
                },
                "quality_notes": {
                  "type": "array",
                  "items": {
                    "type": "string"
                  }
                }
              },
              "required": [
                "document_quality",
                "quality_notes"
              ],
              "propertyOrdering": [
                "document_quality",
                "quality_notes"
              ]
            }
          }
        ]
      }
    ],
    "systemInstruction": {
    }
}
EOF

curl \
-X POST \
-H "Content-Type: application/json" \
"https://generativelanguage.googleapis.com/v1beta/models/${MODEL_ID}:${GENERATE_CONTENT_API}?key=${GEMINI_API_KEY}" -d '@request.json'


