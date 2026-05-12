Get Access Token

# Get Access Token

This is your first endpoint! Edit this page to start documenting your API.

Default: "client\_credentials" Grant type, use: client\_credentialsTo access the API, you need to request an authentication token via the OAuth2 protocol using the client credentials. The API requires the use of an MTLS (Mutual TLS) certificate for mutual authentication, and you must also disable SSL verification.

> Important: Only 10 requests per minute are accepted, otherwise null will be returned.

## Step-by-Step

### 1. Obtain `client_id` and `client_secret` :

The required credentials (client\_id and client\_secret) can be obtained directly by accessing the finance.onlyup.com portal. Once logged in, you can generate or retrieve these credentials, which will be used to authenticate API requests.

<Image border={false} src="https://files.readme.io/9a7ff32316273cc81afe08e82f63c835564eccbfb04d62c5897c12e5bad05664-image.png" />

### 2. Obtain the MTLS Certificate:

To obtain the MTLS certificate required for mutual authentication, the client must contact onlyup. The **onlyup** team will provide the certificate (.crt) and private key (.key) files, which must be used to ensure secure communication with the API.

### 3. Disabling SSL Verification:

Since the API uses a validated SSL certificate, you must disable SSL verification when making requests.

```curl
curl --location 'https://api.pix.onlyup.com/oauth/token' \
--header 'Content-Type: application/json' \
--data '{
    "grant_type" : "client_credentials",
    "client_id" : "xxxxxxxx",
    "client_secret" : "yyyyyyy"
}' \
--cert /path/to/your-certificate.crt \
--key /path/to/your-private-key.key \
-k
```

### 4. Expected Response:

If the request is successful, the response will be a JSON object containing the `access_token`, which should be used in subsequent requests to the API. The default values are:

* `access_token`: The access token to be used for authenticating future requests.
* `expires_in`: The token expires by default in 5 minutes (300 seconds).
* `refresh_expires_in`: The refresh token expiration time is 0 by default.
* `token_type`: The type of token, which will always be Bearer.

## Requirements

* **MTLS Certificate:** The client must contact **Onlyup** to receive the necessary `.crt` (certificate) and `.key` (private key) files for mutual authentication.
* **OAuth Credentials:** `client_id` and `client_secret` can be obtained from the finance.onlyup.com portal.
* **Disable SSL Verification:** Ensure SSL verification is disabled when making requests, as the API already has a validated SSL certificate.

## Add MTLS Certificate in postman to make tests

* The MTLS certificate is mandatory for every request in the onlyup APIs.
* It is important to note that we will provide specific certificates for the cash-in API ([https://api.pix.onlyup.com](https://api.pix.onlyup.com)) and specific ones for the cash-out API ([https://secureapi.onlyup-prod.onz.software](https://secureapionlyup-prod.onz.software)).
* Follow the steps below to configure the certificates for each API in your Postman:
  * grant\_type
    string
    required
    Defaults to client\_credentialsFile >>> Settings (or Ctrl+Comma)

<Image border={false} src="https://files.readme.io/806460905a50061da29d5c45e1e63e6fcf5c47161e1c3becfa4aafebf3712e73-image.png" />

* Certificates >>> Add Certificate..

<Image border={false} src="https://files.readme.io/700ff0fe2d68047923c824f97d45695ab77c53e459cc255ba4555d03909180df-image.png" />

Add the base url of the api to "Host", in this case [https://api.pix.onlyup.com](https://api.pix.onlyup.com) . Place the .crt and .key files we provided in the respective fields. And click in "add"

<Image border={false} src="https://files.readme.io/b62ffd76a80625af275a3a910a78213fccadff1bec29b76380f232711217f01e-image.png" />

<br />

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH IN"
  },
  "servers": [
    {
      "url": "https://api.pix.onlyup.com.br"
    }
  ],
  "paths": {
    "/oauth/token": {
      "post": {
        "summary": "New Endpoint",
        "description": "This is your first endpoint! Edit this page to start documenting your API.",
        "operationId": "get_new-endpoint",
        "responses": {
          "201": {
            "description": "201",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "access_token": {
                      "type": "string"
                    },
                    "expires_in": {
                      "type": "integer",
                      "description": "",
                      "default": "0"
                    },
                    "refresh_expires_in": {
                      "type": "integer",
                      "description": "",
                      "default": "0"
                    },
                    "token_type": {
                      "type": "string"
                    },
                    "not-before-policy": {
                      "type": "integer",
                      "description": "Defaults to 0",
                      "default": "0"
                    },
                    "scope": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "401": {
            "description": "401",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "description": "",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          }
        },
        "requestBody": {
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "grant_type": {
                    "type": "string",
                    "default": "client_credentials",
                    "description": "Defaults to client_credentials Default: \"client_credentials\" Grant type, use: client_credentials"
                  },
                  "client_id": {
                    "type": "string",
                    "description": "Client identifier"
                  },
                  "client_secret": {
                    "type": "string",
                    "description": "Client secret key"
                  }
                },
                "required": [
                  "grant_type",
                  "client_id",
                  "client_secret"
                ]
              }
            }
          }
        }
      }
    }
  },
  "x-readme": {
    "explorer-enabled": true,
    "proxy-enabled": true
  }
}
```


Create QR Code

# Create QR Code

## Suggestion Sequence

<Image border={false} src="https://files.readme.io/11de9db8fe770801c52723a20321a0476f4ab5f22e0a2b9a8d039ff65993698f-image.png" />

This sequence diagram outlines the steps involved in generating a Pix QRCode, handling the payment, and verifying the payment status through a secure process. It also includes error handling and verification mechanisms to ensure the authenticity of payment notifications.

**1. User requests to generate Pix QRCode:**\
The user initiates a request on the website to generate a Pix QRCode for a payment.

**2. Website sends payment details to Onlyup:**\
The website sends a `POST /cob` request to the Onlyup API with the payment details to generate the QRCode.

**3. Handling QRCode generation:**

* **QRCode generation succeeds:**\
  If the QRCode is successfully generated, Onlyup responds with a `200 OK` along with the QRCode payload. The website displays the generated QRCode to the user for payment.
* **QRCode generation fails:**\
  If the generation fails, Onlyup returns a `400 Bad Request` error. The website then displays an error message to the user.

**4. User interaction with the Pix QRCode:**

* **User does not pay:**\
  If the user does not complete the payment, the website times out and notifies the user that the payment was not completed.
* **User completes the payment:**\
  If the user successfully makes the payment, Onlyup sends a `POST /webhook/pix-payment` notification to the website, containing the payment details.

**5. Security check to prevent fake notifications:**\
We use dynamic IPs to send our webhooks, meaning that the IP addresses may change with each sending. Upon receiving the payment webhook, the website performs a security check by querying onlyup's API with a `GET /cob/{txid}` request to verify the status of the payment.

**6. Payment status verification:**

* **Status is CONCLUIDA:**\
  If the payment status is `CONCLUIDA` (completed), the website updates the payment status to 'APPROVED' and informs the user that the payment was successfully approved.
* **Status is REMOVIDA\_PELO\_PSP:**\
  If the payment status is `REMOVIDA_PELO_PSP` (removed by the payment service provider), the website updates the payment status to 'CANCELED' and notifies the user that the payment was canceled.
* **Other status or suspicious activity:**\
  If the status does not match these valid statuses or is considered suspicious, the website marks the payment as invalid or suspicious and informs the user that the payment failed or is invalid.

## Token Validation

Before initiating any request to Onlyup, the website must validate the authentication token. If the token is invalid or expired, Onlyup returns a `401 Unauthorized`, and the website handles this by notifying the user of the authentication failure.

> Important: Only 10 requests per minute are accepted, otherwise null will be returned.

## Qrcode Status

* **ATIVA:**
  Indicates that the record refers to a charge that has been generated but has not yet been paid or removed.
* **CONCLUIDA :**
  Indicates that the record refers to a charge that has already been paid and, therefore, cannot accept another payment.
* **REMOVIDA\_PELO\_USUARIO\_RECEBEDOR:**
  Indicates that the receiving user has requested the removal of the charge record.
* **REMOVIDA\_PELO\_PSP :**
  Indicates that the Receiving PSP (Payment Service Provider) has requested the removal of the charge record.

## Recommendations for the Checkout or Payment Screen

<Image border={false} src="https://files.readme.io/a29e864ee0759c0e5e796f3dfa20c47e66e21fc9e16533aeb80a5fa9088db8c6-image.png" />

**1. Displaying the QR Code**

* Use the `pixCopiaECola` field to display the QR Code on the front-end. This can be done by converting its content into a QR image or directly showing the code in the **Pix Copy and Paste** format.

**2. Pix Copy and Paste Option**

* Provide a dedicated text area for users to copy the Pix code easily (using the `pixCopiaECola` value).
* Include a "Copy Code" button to simplify the process

**3. Countdown Timer**

* Show a countdown timer based on the `expire_at` field to inform users of the remaining time to complete the payment.

**4. Payment Tracking**

* After displaying the QR Code, wait for the payment notification sent by the bank or PSP (Payment Service Provider) to your back-end system.
* Automatically update the order status once the payment is confirmed.

**5. Handling Expired Payments**

* If the payment is not confirmed before the QR Code expires, display a clear message to the user indicating that the code is no longer valid.
* Provide an option to generate a new QR Code to retry the payment.

**6. Error Handling**

* If an issue occurs while generating the QR Code, notify the user with a friendly message.
* Suggest retrying or selecting an alternative payment method.

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH IN"
  },
  "servers": [
    {
      "url": "https://api.pix.onlyup.com.br"
    }
  ],
  "paths": {
    "/cob": {
      "post": {
        "description": "",
        "operationId": "post_cob",
        "responses": {
          "201": {
            "description": "201",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "revisao": {
                      "type": "integer",
                      "description": "",
                      "default": "0"
                    },
                    "loc": {
                      "type": "object",
                      "properties": {
                        "id": {
                          "type": "integer",
                          "default": "0"
                        },
                        "location": {
                          "type": "string",
                          "description": ""
                        },
                        "tipoCob": {
                          "type": "string"
                        },
                        "criacao": {
                          "type": "string"
                        }
                      }
                    },
                    "location": {
                      "type": "string"
                    },
                    "calendario": {
                      "type": "object",
                      "properties": {
                        "criacao": {
                          "type": "string"
                        },
                        "expiracao": {
                          "type": "integer",
                          "description": "",
                          "default": "0"
                        }
                      }
                    },
                    "devedor": {
                      "type": "object",
                      "properties": {
                        "cpf": {
                          "type": "string"
                        },
                        "nome": {
                          "type": "string"
                        }
                      }
                    },
                    "valor": {
                      "type": "object",
                      "properties": {
                        "original": {
                          "type": "string"
                        },
                        "modalidadeAlteracao": {
                          "type": "integer",
                          "default": "0"
                        }
                      }
                    },
                    "chave": {
                      "type": "string"
                    },
                    "txid": {
                      "type": "string"
                    },
                    "status": {
                      "type": "string"
                    },
                    "infoAdicionais": {
                      "type": "array",
                      "items": {
                        "properties": {
                          "nome": {
                            "type": "string"
                          },
                          "valor": {
                            "type": "string"
                          }
                        },
                        "type": "object"
                      }
                    },
                    "pixCopiaECola": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "400": {
            "description": "400",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    },
                    "violacoes": {
                      "type": "array",
                      "items": {
                        "properties": {
                          "razao": {
                            "type": "string"
                          },
                          "propriedade": {
                            "type": "string"
                          }
                        },
                        "type": "object"
                      }
                    }
                  }
                }
              }
            }
          },
          "403": {
            "description": "403",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "503": {
            "description": "503",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          }
        },
        "parameters": [],
        "requestBody": {
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "calendario": {
                    "type": "object",
                    "properties": {
                      "expiracao": {
                        "type": "integer",
                        "format": "int32",
                        "default": "3600",
                        "description": "QRcode expiration time (in seconds)"
                      }
                    },
                    "required": [
                      "expiracao"
                    ]
                  },
                  "devedor": {
                    "type": "object",
                    "properties": {
                      "cpf": {
                        "type": "string",
                        "default": "91070297054",
                        "description": "CPF of the user requesting a QR code."
                      },
                      "nome": {
                        "type": "string",
                        "description": "Name of the user requesting a QR code.",
                        "default": "Nome Fulano"
                      }
                    },
                    "required": [
                      "cpf",
                      "nome"
                    ]
                  },
                  "valor": {
                    "type": "object",
                    "properties": {
                      "original": {
                        "type": "string",
                        "default": "37.00",
                        "description": "Monetary values related to the charge (example \"37.00\")"
                      },
                      "modalidadeAlteracao": {
                        "type": "integer",
                        "description": "Always keep the value 0 (If you put 1, the payer will be able to edit the amount.)",
                        "format": "int32"
                      }
                    },
                    "required": [
                      "original",
                      "modalidadeAlteracao"
                    ]
                  },
                  "chave": {
                    "type": "string",
                    "description": "string (Recipient’s DICT key) <= 77 characters",
                    "default": "46d4fd4d-acfd-4e2b-81f9-cad2d065b6c7"
                  },
                  "infoAdicionais": {
                    "type": "array",
                    "items": {
                      "properties": {
                        "nome": {
                          "type": "string",
                          "default": "Solicitação do pagador"
                        },
                        "valor": {
                          "type": "string"
                        }
                      },
                      "type": "object"
                    },
                    "description": "Each respective additional piece of information contained in the list (name and value) must be presented to the payer."
                  }
                },
                "required": [
                  "chave"
                ]
              }
            }
          }
        }
      }
    }
  },
  "x-readme": {
    "explorer-enabled": true,
    "proxy-enabled": true
  }
}
```
Create QR Code

# Create QR Code

## Suggestion Sequence

This sequence diagram outlines the steps involved in generating a Pix QRCode, handling the payment, and verifying the payment status through a secure process. It also includes error handling and verification mechanisms to ensure the authenticity of payment notifications.

**1. User requests to generate Pix QRCode:**\
The user initiates a request on the website to generate a Pix QRCode for a payment.

**2. Website sends payment details to Onlyup:**\
The website sends a `PUT /cob/{txid}` request to the Onlyup API with the payment details to generate the QRCode

**3. Handling QRCode generation:**

* **QRCode generation succeeds:**
  If the QRCode is successfully generated, Onlyup responds with a `200 OK` along with the QRCode payload. The website displays the generated QRCode to the user for payment.
* **QRCode generation fails:**\
  If the generation fails, Onlyup returns a `400 Bad Request error`. The website then displays an error message to the user.

**4. User interaction with the Pix QRCode:**

* **User does not pay:**
  If the user does not complete the payment, the website times out and notifies the user that the payment was not completed.
* **User completes the payment:**
  If the user successfully makes the payment, Onlyup sends a `POST /webhook/pix-payment` notification to the website, containing the payment details.

**5. Security check to prevent fake notifications:**\
We use dynamic IPs to send our webhooks, meaning that the IP addresses may change with each sending. Upon receiving the payment webhook, the website performs a security check by querying Onlyup's API with a `GET /cob/{txid}` request to verify the status of the payment.

**6. Payment status verification:**

* **Status is CONCLUIDA:**
  If the payment status is `CONCLUIDA` (completed), the website updates the payment status to 'APPROVED' and informs the user that the payment was successfully approved.
* **Status is REMOVIDA\_PELO\_PSP:**
  If the payment status is `REMOVIDA_PELO_PSP` (removed by the payment service provider), the website updates the payment status to 'CANCELED' and notifies the user that the payment was canceled.
* **Other status or suspicious activity:**\
  If the status does not match these valid statuses or is considered suspicious, the website marks the payment as invalid or suspicious and informs the user that the payment failed or is invalid.

## Token Validation

Before initiating any request to Onlyup, the website must validate the authentication token. If the token is invalid or expired, Onlyup returns a `401 Unauthorized`, and the website handles this by notifying the user of the authentication failure.

> **Important:** Only 10 requests per minute are accepted, otherwise null will be returned.

## Qrcode Status

* **ATIVA:**
  Indicates that the record refers to a charge that has been generated but has not yet been paid or removed.
* **CONCLUIDA :**
  Indicates that the record refers to a charge that has already been paid and, therefore, cannot accept another payment.
* **REMOVIDA\_PELO\_USUARIO\_RECEBEDOR:**
  Indicates that the receiving user has requested the removal of the charge record.
* **REMOVIDA\_PELO\_PSP :**
  Indicates that the Receiving PSP (Payment Service Provider) has requested the removal of the charge record.

## Recommendations for the Checkout or Payment Screen

<Image border={false} src="https://files.readme.io/f5b90535153c1ec8bd1bd3167936f1494a0b407024a693cce1ccd3607f102e15-image.png" />

**1. Displaying the QR Code**

* Use the `pixCopiaECola` field to display the QR Code on the front-end. This can be done by converting its content into a QR image or directly showing the code in the **Pix Copy and Paste** format.

**2. Pix Copy and Paste Option**

* Provide a dedicated text area for users to copy the Pix code easily (using the `pixCopiaECola` value).
* Include a "Copy Code" button to simplify the process.

**3. Countdown Timer**

* Show a countdown timer based on the `expire_at` field to inform users of the remaining time to complete the payment

**4. Payment Tracking**

* After displaying the QR Code, wait for the payment notification sent by the bank or PSP (Payment Service Provider) to your back-end system.
* Automatically update the order status once the payment is confirmed.

**5. Handling Expired Payments**

* If the payment is not confirmed before the QR Code expires, display a clear message to the user indicating that the code is no longer valid.
* Provide an option to generate a new QR Code to retry the payment.

**6. Error Handling**

* If an issue occurs while generating the QR Code, notify the user with a friendly message.
* Suggest retrying or selecting an alternative payment method.

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH IN"
  },
  "servers": [
    {
      "url": "https://api.pix.onlyup.com.br"
    }
  ],
  "paths": {
    "/cob/{txid}": {
      "put": {
        "description": "",
        "operationId": "put_cob{txid}",
        "responses": {
          "200": {
            "description": "200",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "revisao": {
                      "type": "integer",
                      "default": "0"
                    },
                    "loc": {
                      "type": "object",
                      "properties": {
                        "id": {
                          "type": "integer",
                          "default": "0"
                        },
                        "location": {
                          "type": "string"
                        },
                        "tipoCob": {
                          "type": "string"
                        },
                        "criacao": {
                          "type": "string"
                        }
                      }
                    },
                    "location": {
                      "type": "string"
                    },
                    "calendario": {
                      "type": "object",
                      "properties": {
                        "criacao": {
                          "type": "string"
                        },
                        "expiracao": {
                          "type": "integer",
                          "default": "0"
                        }
                      }
                    },
                    "devedor": {
                      "type": "object",
                      "properties": {
                        "cpf": {
                          "type": "string"
                        },
                        "nome": {
                          "type": "string"
                        }
                      }
                    },
                    "valor": {
                      "type": "object",
                      "properties": {
                        "original": {
                          "type": "string"
                        },
                        "modalidadeAlteracao": {
                          "type": "integer",
                          "default": "0"
                        }
                      }
                    },
                    "chave": {
                      "type": "string"
                    },
                    "txid": {
                      "type": "string"
                    },
                    "infoAdicionais": {
                      "type": "array",
                      "items": {
                        "properties": {
                          "nome": {
                            "type": "string"
                          },
                          "valor": {
                            "type": "string"
                          }
                        },
                        "type": "object"
                      }
                    },
                    "pixCopiaECola": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "400": {
            "description": "400",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    },
                    "violacoes": {
                      "type": "array",
                      "items": {
                        "properties": {
                          "razao": {
                            "type": "string"
                          },
                          "propriedade": {
                            "type": "string"
                          }
                        },
                        "type": "object"
                      }
                    }
                  }
                }
              }
            }
          },
          "403": {
            "description": "403",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "503": {
            "description": "503",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          }
        },
        "parameters": [
          {
            "in": "path",
            "name": "txid",
            "schema": {
              "type": "string"
            },
            "required": true
          }
        ],
        "requestBody": {
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "calendario": {
                    "type": "object",
                    "description": "",
                    "properties": {
                      "expiracao": {
                        "type": "integer",
                        "description": "QRcode expiration time (in seconds)",
                        "format": "int32",
                        "default": "3600"
                      }
                    },
                    "required": [
                      "expiracao"
                    ]
                  },
                  "devedor": {
                    "type": "object",
                    "properties": {
                      "cpf": {
                        "type": "string",
                        "description": "CPF of the user requesting a QR code.",
                        "default": "91070291054"
                      },
                      "nome": {
                        "type": "string",
                        "description": "Name of the user requesting a QR code.",
                        "default": "Nome fulano"
                      }
                    }
                  },
                  "valor": {
                    "type": "object",
                    "properties": {
                      "original": {
                        "type": "string",
                        "description": "Monetary values related to the charge (example \"37.00\")",
                        "default": "37.00"
                      },
                      "modalidadeAlteracao": {
                        "type": "string",
                        "description": "Always keep the value 0 (If you put 1, the payer will be able to edit the amount.)"
                      }
                    },
                    "required": [
                      "original",
                      "modalidadeAlteracao"
                    ]
                  },
                  "chave": {
                    "type": "string",
                    "description": "string (Recipient’s DICT key) <= 77 characters",
                    "default": "46d4fd4d-acfd-4e2b-81f9-cad2d065b6c7"
                  },
                  "infoAdicionais": {
                    "type": "array",
                    "items": {
                      "properties": {
                        "nome": {
                          "type": "string",
                          "default": "Solicitação do pagador"
                        },
                        "valor": {
                          "type": "string"
                        }
                      },
                      "type": "object"
                    },
                    "description": "Each respective additional piece of information contained in the list (name and value) must be presented to the payer."
                  }
                },
                "required": [
                  "chave"
                ]
              }
            }
          }
        }
      }
    }
  },
  "x-readme": {
    "explorer-enabled": true,
    "proxy-enabled": true
  }
}
```
Get QR Code Details

# Get QR Code Details

Endpoint to query a charge using a specific txid.

## Qrcode Status

* **ATIVA:**
  Indicates that the record refers to a charge that has been generated but has not yet been paid or removed.
* **CONCLUIDA :**
  Indicates that the record refers to a charge that has already been paid and, therefore, cannot accept another payment.
* **REMOVIDA\_PELO\_USUARIO\_RECEBEDOR:**
  Indicates that the receiving user has requested the removal of the charge record.
* **REMOVIDA\_PELO\_PSP :**
  Indicates that the Receiving PSP (Payment Service Provider) has requested the removal of the charge record.

<br />

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH IN"
  },
  "servers": [
    {
      "url": "https://api.pix.onlyup.com.br"
    }
  ],
  "paths": {
    "/cob/{txid}": {
      "get": {
        "description": "",
        "operationId": "get_cob{txid}",
        "responses": {
          "200": {
            "description": "200",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "calendario": {
                      "type": "object",
                      "properties": {
                        "criacao": {
                          "type": "string",
                          "description": "",
                          "default": ""
                        },
                        "expiracao": {
                          "type": "integer",
                          "default": "0"
                        }
                      }
                    },
                    "txid": {
                      "type": "string"
                    },
                    "revisao": {
                      "type": "integer",
                      "default": "0"
                    },
                    "loc": {
                      "type": "object",
                      "properties": {
                        "id": {
                          "type": "integer",
                          "default": "0"
                        },
                        "location": {
                          "type": "string"
                        },
                        "tipoCob": {
                          "type": "string"
                        }
                      }
                    },
                    "location": {
                      "type": "string"
                    },
                    "status": {
                      "type": "string"
                    },
                    "devedor": {
                      "type": "object",
                      "properties": {
                        "cnpj": {
                          "type": "string"
                        },
                        "nome": {
                          "type": "string"
                        }
                      }
                    },
                    "valor": {
                      "type": "object",
                      "properties": {
                        "original": {
                          "type": "string"
                        },
                        "modalidadeAlteracao": {
                          "type": "integer",
                          "default": "0"
                        }
                      }
                    },
                    "chave": {
                      "type": "string"
                    },
                    "solicitacaoPagador": {
                      "type": "string"
                    },
                    "infoAdicionais": {
                      "type": "array",
                      "items": {
                        "properties": {
                          "object": {
                            "type": "object",
                            "properties": {
                              "nome": {
                                "type": "string"
                              },
                              "valor": {
                                "type": "string"
                              }
                            }
                          }
                        },
                        "type": "object"
                      }
                    }
                  }
                }
              }
            }
          },
          "403": {
            "description": "403",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "object": {
                      "type": "object",
                      "properties": {
                        "type": {
                          "type": "string"
                        },
                        "title": {
                          "type": "string"
                        },
                        "status": {
                          "type": "integer",
                          "default": "0"
                        },
                        "detail": {
                          "type": "string"
                        }
                      }
                    }
                  }
                }
              }
            }
          },
          "404": {
            "description": "404",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "Type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "503": {
            "description": "503",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          }
        },
        "parameters": [
          {
            "in": "path",
            "name": "txid",
            "schema": {
              "type": "string"
            },
            "required": true,
            "description": "The txid field determines the identifier of the transaction. The purpose of this field is to serve as an element that enables the recipient's PSP to present the payment reconciliation feature to the recipient user. In the pacs.008, it is referenced as TransactionIdentification or idConciliacaoRecebedor. In terms of operational flow, the txid is read by the payer's PSP application, and after the payment is confirmed, it is sent to the SPI via pacs.008. A pacs.008 is also sent to the recipient's PSP, containing, in addition to all the usual payment information, the txid. Upon detecting a receipt with a txid, the recipient's PSP is able to communicate with the recipient user, informing them that a specific payment has been settled. The txid is created exclusively by the recipient user and is their responsibility. The txid, in the context of representing a charge, is unique per CPF/CNPJ of the recipient user. It is the responsibility of the recipient's PSP to validate this rule in the Pix API."
          }
        ]
      }
    }
  },
  "x-readme": {
    "explorer-enabled": true,
    "proxy-enabled": true
  }
}
```
Configure Webhook

# Configure Webhook

Endpoint for setting up the notification service for received Pix transactions. Only Pix transactions associated with a txid will be notified.

## Callbacks

The callback should be triggered whenever one or more Pix transactions associated with a txid are received by the payee, as long as the key associated with the Pix transaction is linked to a registered webhook.

The callback should also be triggered whenever a refund associated with a Pix transaction linked to a txid reaches a final status: `DEVOLVIDO` or `NAO_REALIZADO`.

The specific SLA to be defined for triggering callbacks is at the discretion of each receiving PSP. However, it is recommended that the SLA be set within a reasonable timeframe, considering that the expectation is for the callback to serve as an "online" notification of payment occurrence.

In the context of each receiving PSP’s specific SLA strategy, it is possible to group Pix transactions associated with the same key to reduce multiple trigger events. This service is protected by an mTLS authentication layer. For more details, refer to the Pix Initiation Standards Manual.

## Callback payload samples

```json
{
  "txid": "971122d8f37211eaadc10242ac120002",
  "valor": "110.00",
  "horario": "2020-09-09T20:15:00.358Z",
  "pagador": {
  "cpf": "0123456789",
  "nome": "Nome Pagador"
  },
  "endToEndId": "E12345678202009091221abcdef12345"
}
```

## Callback payload devolution samples

```
{
  "pix": {
    "txid": "c3e0e7a4e7f1469a9f782d3d4999343c",
    "valor": "110.00",
    "horario": "2020-09-09T20:15:00.358Z",
    "pagador": {
      "cpf": "0123456789",
      "nome": "Nome Pagador"
    },
    "devolucoes": {
      "id": "123ABC",
      "rtrId": "D12345678202009091221abcdf098765",
      "valor": "10.00",
      "status": "DEVOLVIDO",
      "horario": {
        "liquidacao": "2020-09-09T20:15:00.358Z",
        "solicitacao": "2020-09-09T20:15:00.358Z"
      },
      "natureza": "ORIGINAL"
    },
    "endToEndId": "E12345678202009091221abcdef12345"
  }
}
```

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH IN"
  },
  "servers": [
    {
      "url": "https://api.pix.onlyup.com.br"
    }
  ],
  "paths": {
    "/webhook/{chave}": {
      "put": {
        "description": "",
        "operationId": "put_webhook{chave}",
        "responses": {
          "200": {
            "description": "200",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "webhookUrl": {
                      "type": "string"
                    },
                    "chave": {
                      "type": "string"
                    },
                    "criacao": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "400": {
            "description": "400",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "403": {
            "description": "403",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "404": {
            "description": "404",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "503": {
            "description": "503",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          }
        },
        "parameters": [
          {
            "in": "path",
            "name": "chave",
            "schema": {
              "type": "string"
            },
            "required": true,
            "description": "string (Receiver's DICT Key) <= 77 characters"
          }
        ],
        "requestBody": {
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "webhookUrl": {
                    "type": "string",
                    "description": "url"
                  }
                },
                "required": [
                  "webhookUrl"
                ]
              }
            }
          }
        }
      }
    }
  },
  "x-readme": {
    "explorer-enabled": true,
    "proxy-enabled": true
  }
}
```
Request for Refund

# Request for Refund

Endpoint to request a return using the Pix E2EID and the return ID. The reason to be assigned to the PACS.004 will be either "MD06" or "SL02" as specified in the RTReason tab of the PACS.004 message, according to the Pix Message Catalogue, depending on the nature of the return (see description of this field).

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH IN"
  },
  "servers": [
    {
      "url": "https://api.pix.onlyup.com.br"
    }
  ],
  "paths": {
    "/pix/{e2eid}/devolucao/{id}": {
      "put": {
        "description": "",
        "operationId": "put_pix{e2eid}devolucao{id}",
        "responses": {
          "201": {
            "description": "201",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "id": {
                      "type": "string"
                    },
                    "rtrId": {
                      "type": "string"
                    },
                    "valor": {
                      "type": "string"
                    },
                    "horario": {
                      "type": "object",
                      "properties": {
                        "solicitacao": {
                          "type": "string"
                        }
                      }
                    },
                    "status": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "400": {
            "description": "400",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "403": {
            "description": "403",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "nadetailme3": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "404": {
            "description": "404",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string"
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "503": {
            "description": "503",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "type": {
                      "type": "string",
                      "description": ""
                    },
                    "title": {
                      "type": "string"
                    },
                    "status": {
                      "type": "integer",
                      "default": "0"
                    },
                    "detail": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          }
        },
        "parameters": [
          {
            "in": "path",
            "name": "e2eid",
            "schema": {
              "type": "string"
            },
            "required": true,
            "description": "EndToEndIdentification that flows through PACS.002, PACS.004, and PACS.008"
          },
          {
            "in": "path",
            "name": "id",
            "schema": {
              "type": "string"
            },
            "required": true,
            "description": "ID generated by the client to uniquely represent a refund"
          }
        ],
        "requestBody": {
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "valor": {
                    "type": "string",
                    "description": "Requested amount for return. The sum of all return amounts cannot exceed the total value of the Pix payment.",
                    "default": "7.89"
                  },
                  "natureza": {
                    "type": "string",
                    "description": "Indicates the nature of the return request. A return request by the recipient user can be related to a standard Pix (with code: MD06 in PACS.004), or to a Pix Withdrawal or Change Pix (with possible codes: MD06 and SL02 in PACS.004). In the absence of this field, the nature should be interpreted as a standard Pix (ORIGINAL).",
                    "enum": [
                      "ORIGINAL",
                      "RETIRADA"
                    ],
                    "default": "ORIGINAL"
                  },
                  "descricao": {
                    "type": "string",
                    "description": "The description field, optional, specifies a text to be presented to the payer with information about the return. This text will be filled in the PACS.004 by the recipient's PSP in the RemittanceInformation field. The field size in the PACS.004 is limited to 140 characters."
                  }
                },
                "required": [
                  "valor"
                ]
              }
            }
          }
        }
      }
    }
  },
  "x-readme": {
    "explorer-enabled": true,
    "proxy-enabled": true
  }
}
```
