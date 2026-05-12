Get Access Token

# Get Access Token

To access the API, you need to request an authentication token via the OAuth2 protocol using the client credentials. The API requires the use of an MTLS (Mutual TLS) certificate for mutual authentication, and you must also disable SSL verification.

> **Important:** Only 10 requests per minute are accepted, otherwise null will be returned.

## Step-by-Step

#### 1. Obtain `client_id andclient_secret`:

The required credentials (`client_id and client_secret`) can be obtained directly by accessing the finance.onlyup.com portal. Once logged in, you can generate or retrieve these credentials, which will be used to authenticate API requests.

#### 2. Obtain the MTLS Certificate:

To obtain the MTLS certificate required for mutual authentication, the client must contact **Onlyup**. The **Onlyup** team will provide the certificate (`.crt`) and private key (`.key`) files, which must be used to ensure secure communication with the API.

#### 3. Disabling SSL Verification:

Since the API uses a validated SSL certificate, you must disable SSL verification when making requests.

```Text bash
curl --location 'https://accounts.onlyup.com.br/api/v2/oauth/token' \
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

#### 4. Expected Response:

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

* The MTLS certificate is mandatory for every request in the Pagstar APIs.
* It is important to note that we will provide specific certificates for the cash-in API ([https://api.pix.onlyup.com](https://api.pix.onlyup.com)) and specific ones for the cash-out API [https://secureapi.onlyup-prod.onz.software](https://secureapi.onlyup-prod.onz.software).
* Follow the steps below to configure the certificates for each API in your Postman:
  * File >>> Settings (or Ctrl+Comma)
  * ![](https://files.readme.io/663332d39fb84477492abd0ccaffa941cec7b81cebe9dd07c6533230f3219454-image.png)

    Certificates >>> Add Certificate..

    ![](https://files.readme.io/fc51247160611a062474127c197f9cc7ed762821c4a24242759e714c8194c248-image.png)
* Add the base url of the api to "Host", in this case [https://secureapi.onlyup-prod.onz.software](https://secureapi.pagstar-prod.onz.software) . Place the .crt and .key files we provided in the respective fields. And click in "add"

  ![](https://files.readme.io/ff49a3e048584f8e83c5017acac39d1cd31fa50420cb54161174d610a8aa6e39-image.png)

<br />

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH OUT"
  },
  "servers": [
    {
      "url": "https://accounts.onlyup.com.br/"
    }
  ],
  "paths": {
    "/api/v2/oauth/token": {
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
                    "tokenType": {
                      "type": "string"
                    },
                    "expiresAt": {
                      "type": "integer",
                      "default": "0"
                    },
                    "refreshExpiresIn": {
                      "type": "integer",
                      "default": "0"
                    },
                    "notBeforePolicy": {
                      "type": "integer",
                      "default": "0"
                    },
                    "accessToken": {
                      "type": "string",
                      "default": ""
                    },
                    "scope": {
                      "type": "string"
                    }
                  },
                  "title": "Response body"
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "500": {
            "description": "500",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  },
                  "title": "Response body"
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
                    "description": "Defaults to client_credentials Default: \"client_credentials\" Grant type, use: client_credentials",
                    "default": "client_credentials"
                  },
                  "client_id": {
                    "type": "string",
                    "description": "Defaults to Client identifier Token de acesso.",
                    "default": "Client identifier"
                  },
                  "client_secret": {
                    "type": "string",
                    "description": "Client secret key"
                  }
                },
                "required": [
                  "grant_type",
                  "client_secret",
                  "client_id"
                ]
              }
            }
          }
        },
        "parameters": []
      }
    }
  },
  "x-readme": {
    "explorer-enabled": true,
    "proxy-enabled": true
  }
}
```
Get Account Balance

# Get Account Balance

Saldo disponível de sua conta

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH OUT"
  },
  "servers": [
    {
      "url": "https://accounts.onlyup.com.br/"
    }
  ],
  "paths": {
    "/api/v2/accounts/balances/": {
      "get": {
        "description": "",
        "operationId": "get_apiv2accountsbalances",
        "responses": {
          "200": {
            "description": "200",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "data": {
                      "type": "array",
                      "items": {
                        "properties": {
                          "eventDate": {
                            "type": "string"
                          },
                          "balanceAmount": {
                            "type": "object",
                            "properties": {
                              "name": {
                                "type": "string"
                              },
                              "available": {
                                "type": "number",
                                "default": "0"
                              },
                              "blocked": {
                                "type": "number",
                                "default": "0"
                              },
                              "overdraft": {
                                "type": "number",
                                "default": "0"
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "429": {
            "description": "429",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "500": {
            "description": "500",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          }
        },
        "parameters": []
      }
    }
  },
  "x-readme": {
    "explorer-enabled": true,
    "proxy-enabled": true
  }
}
```
Transfer By Pix Key

# Transfer By Pix Key

This endpoint allows requesting a Pix payment order (cash-out) using any of the allowed types of pix key: CPF, CNPJ, address e-mail, phone number, EVP (random key).

## Suggestion Sequence

<Image border={false} src="https://files.readme.io/b163879a7afe76930e769778623e6f8513ce60e5d2f3453878dc4dd67f2af6fd-image.png" />

<br />

This sequence diagram illustrates the flow of a Pix withdrawal request, verification of balance, transfer processing, and the subsequent security checks using webhooks to confirm the status of the transfer.

### 1. User requests withdrawal:

The process begins when the user submits a withdrawal request on the website, including the necessary Pix key information.

### 2. Website checks available balance:

The website sends a `GET /api/v2/accounts/balances/` request to the Onlyup API to verify if the user has sufficient funds to proceed with the withdrawal.

* If the token is invalid or expired, Onlyup returns a `401 Unauthorized`, and the website informs the user that the request failed due to invalid authentication.

### 3. Handling sufficient/insufficient balance:

* **Sufficient balance:**
  If the balance is sufficient, the website proceeds by sending a POST /api/v2/pix/payments/dict request to Onlyup with the transfer details. Onlyup responds with 202 Accepted, indicating that the transfer request has been received but is still being processed. The website informs the user that the transfer request was successfully submitted.
* **Insufficient balance:**
  If the balance is insufficient, the website informs the user with a temporary error message, asking them to try again later.

### 4. Onlyup sends confirmation webhook:

Once the transfer is processed, Onlyup sends a `POST /webhook/transfer-confirmation` to notify the website about the status of the transfer. The webhook could indicate one of the following statuses: `LIQUIDATED` or `CANCELED`.

### 5. Security check to prevent fake notifications:

We use dynamic IPs to send our webhooks, meaning that the IP addresses may change with each sending. Upon receiving the webhook, the website performs a security check by querying Onlyup with a` GET /api/v2/pix/payments/idempotencyKey/{idempotencyKey}` request to verify the actual transfer status, ensuring that the webhook was not falsified.

### 6. Transfer status:

* LIQUIDATED:
  If the status returned is `LIQUIDATED`, the website updates the transfer status to 'LIQUIDATED' and informs the user that the transfer was successfully completed.
* CANCELED:
  If the status returned is `CANCELED`, the website updates the transfer status to 'CANCELED' and informs the user that the transfer was canceled.
* Status different from that received in the webhook:
  If the status returned in the verification differs from the status initially provided in the webhook, the website marks the transfer as suspicious or invalid and informs the user that the transfer failed or was flagged as invalid.

Pix Key

Chave Pix is like a "unique address" you can use to receive instant payments in Brazil quickly and easily. This key acts as a unique identifier linked to your bank account in Brazil. Even if you are in the USA, you can have a Brazilian bank account and use Pix. The Chave Pix can be associated with various types of information, such as CPF/CNPJ, phone number, email, or even a random key generated by the system. This variety allows you to choose the most convenient way to receive payments without needing to share sensitive banking information, like bank account and branch numbers. When someone makes a transfer to you, you simply provide your Chave Pix, whether it's CPF/CNPJ, phone, email, or random key. This greatly simplifies the payment process, as it eliminates the need to fill in various banking details, as was required previously.

| Type of Pix Key  | Description                                          | Format Validation                                      |
| :--------------- | :--------------------------------------------------- | :----------------------------------------------------- |
| CPF              | Tax identification number                            | `^[0-9]{11}$`                                          |
| CNPJ             | Tax number of the business                           | `^[0-9]{14}$`                                          |
| Phone Number     | Phone number                                         | ´^+\[1-9]\[0-9]\d{14}$´                                |
| Email            | Email address                                        | `^[a-z0-9.!#$&'*+\/=?^_{\|}~-]+@a-z0-9?(?:.a-z0-9?)*$` |
| EVP (Random Key) | Random key (generated by the Central Bank of Brazil) | `[0-9a-f]8-[0-9a-f]4-[0-9a-f]4-[0-9a-f]4-[0-9a-f]12$`  |

Token Validation

Before making any API requests to Onlyup, the website must ensure that the token used for authentication is valid.

* If the token is invalid or expired, Onlyup will return a `401 Unauthorized` response, and the website must handle this by notifying the user that the operation could not be completed due to authentication issues.

Transfer Status

* **CANCELED :**
  Indicates that there was some failure in processing the transfer, and the request is canceled.
* **PROCESSING :**
  Indicates that the transfer is still in the process of being sent to the recipient's account.
* **LIQUIDATED:**
  Indicates that the transfer has been successfully processed and credited to the recipient's account.
* **REFUNDED:**
  Indicates that the transfer was successfully credited to the recipient's account, but the recipient returned the full amount.
* **PARTIALLY\_REFUNDED:**
  Indicates that the transfer was successfully credited to the recipient's account, but the recipient returned part of the amount.

<br />

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH OUT"
  },
  "servers": [
    {
      "url": "https://accounts.onlyup.com.br/"
    }
  ],
  "paths": {
    "/api/v2/pix/payments/dict": {
      "post": {
        "description": "",
        "operationId": "post_apiv2pixpaymentsdict",
        "responses": {
          "202": {
            "description": "202",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "data": {
                      "type": "object",
                      "properties": {
                        "id": {
                          "type": "integer",
                          "default": "0"
                        },
                        "refunds": {
                          "type": "array",
                          "items": {
                            "type": "number"
                          }
                        },
                        "idempotencyKey": {
                          "type": "string"
                        },
                        "endToEndId": {
                          "type": "string"
                        },
                        "pixKey": {
                          "type": "string"
                        },
                        "payment": {
                          "type": "object",
                          "properties": {
                            "currency": {
                              "type": "string",
                              "description": ""
                            },
                            "amount": {
                              "type": "number",
                              "default": "0"
                            }
                          }
                        },
                        "status": {
                          "type": "string"
                        },
                        "transactionType": {
                          "type": "string"
                        },
                        "localInstrument": {
                          "type": "string"
                        },
                        "createdAt": {
                          "type": "string"
                        },
                        "creditorAccount": {
                          "type": "object",
                          "properties": {
                            "ispb": {
                              "type": "string"
                            },
                            "document": {
                              "type": "string"
                            },
                            "name": {
                              "type": "string"
                            },
                            "number": {
                              "type": "string"
                            },
                            "issuer": {
                              "type": "string"
                            },
                            "accountType": {
                              "type": "string"
                            }
                          }
                        },
                        "debtorAccount": {
                          "type": "object",
                          "properties": {
                            "ispb": {
                              "type": "string"
                            },
                            "document": {
                              "type": "string"
                            },
                            "name": {
                              "type": "string"
                            },
                            "number": {
                              "type": "string"
                            },
                            "issuer": {
                              "type": "string"
                            },
                            "accountType": {
                              "type": "string"
                            }
                          }
                        },
                        "remittanceInformation": {
                          "type": "string"
                        },
                        "errorCode": {
                          "type": "string"
                        },
                        "txId": {
                          "type": "string"
                        },
                        "creditDebitType": {
                          "type": "string"
                        }
                      }
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "412": {
            "description": "412",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "422": {
            "description": "422",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "429": {
            "description": "429",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "500": {
            "description": "500",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
            "in": "header",
            "name": "x-idempotency-key",
            "schema": {
              "type": "string"
            },
            "description": "Unique request identifier to support idempotency.",
            "required": true
          }
        ],
        "requestBody": {
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "pixKey": {
                    "type": "string",
                    "description": "Any of allowed keys: CPF, CNPJ, address e-mail, phone number, EVP (random key)",
                    "default": "11111111111"
                  },
                  "creditorDocument": {
                    "type": "string",
                    "default": "string"
                  },
                  "priority": {
                    "type": "string",
                    "enum": [
                      "HIGH",
                      "NORM"
                    ],
                    "description": "When the parameter is set to 'HIGH,' it is processed instantly (bypassing the processing queue). The value 'HIGH' is only allowed when the 'creditorDocument' field is defined.",
                    "default": "HIGH"
                  },
                  "description": {
                    "type": "string",
                    "description": "Descrição da transação",
                    "default": "string"
                  },
                  "expiration": {
                    "type": "integer",
                    "format": "int32",
                    "default": "600",
                    "description": "[1 .. 10800] Maximum time (seconds) that an operation can remain in the queue waiting for processing before being cancelled."
                  },
                  "payment": {
                    "type": "object",
                    "properties": {
                      "currency": {
                        "type": "string",
                        "default": "BRL",
                        "description": "currency format (for now, only BRL is available)"
                      },
                      "amount": {
                        "type": "string",
                        "description": "Minimum value of 0.01",
                        "default": "0.1"
                      }
                    },
                    "required": [
                      "amount",
                      "currency"
                    ]
                  },
                  "ispbDeny": {
                    "type": "array",
                    "description": "List of codes ISPB (Identificador de Sistema de Pagamentos Brasileiro) to which payments will not be allowed.",
                    "items": {
                      "type": "string"
                    }
                  }
                },
                "required": [
                  "pixKey",
                  "creditorDocument"
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
Get Transfer Details

# Get Transfer Details

Saldo disponível de sua conta

Transfer Status

* **CANCELED:**
  Indicates that there was some failure in processing the transfer, and the request is canceled.
* **PROCESSING:**
  Indicates that the transfer is still in the process of being sent to the recipient's account.
* **LIQUIDATED:**
  Indicates that the transfer has been successfully processed and credited to the recipient's account.
* **REFUNDED:**
  Indicates that the transfer was successfully credited to the recipient's account, but the recipient returned the full amount.
* **PARTIALLY\_REFUNDED:**
  Indicates that the transfer was successfully credited to the recipient's account, but the recipient returned part of the amount.

Error Codes

In the "errorCode" parameter we inform, through a code, the error that caused the cancellation of a transfer, check the description of each code below:

* **AB03:** Transaction settlement interrupted due to SPI timeout.
* **AB09:** Transaction interrupted due to an error with the recipient's participant.
* **AB11:** Timeout of the participant initiating the payment order.
* **AC03:** The agency and/or transactional account number of the recipient user is nonexistent or invalid.
* **AC06:** The recipient user's transactional account is blocked.
* **AC07:** The recipient user's transactional account has been closed.
* **AC14:** Incorrect type for the recipient's transactional account.
* **AG03:** The type of transaction is not supported/authorized on the recipient's transactional account. Example: transfer to a salary account.
* **AG13:** It is not allowed to return the refund of an instant payment.
* **AGNT:** Direct participant is not the clearing participant for the payer's participant.
* **AM01:** Instant payment order with zero value.
* **AM02:** Payment/refund order exceeds the permitted limit for the type of credited transactional account.
* **AM09:** Payment refund exceeds the amount of the corresponding instant payment order.
* **AM18:** Invalid number of transactions.
* **BE01:** The CPF/CNPJ of the recipient user is not consistent with the account holder of the specified transactional account.
* **BE05:** The CNPJ of the payment initiator is not registered in the Pix arrangement.
* **BE17:** QR Code rejected by the recipient user's participant.
* **CH11:** The CPF/CNPJ of the recipient user is incorrect.
* **CH16:** Incorrect or incompatible message content with business rules.
* **DS04:** Order rejected by the recipient user's participant.
* **DT02:** Invalid date and time of message submission.
* **DT05:** Transaction exceeds the maximum regulatory deadline for an instant payment refund set by the Pix arrangement.
* **ED05:** Error in processing the instant payment.
* **FF07:** Inconsistency between the purpose of the transaction and the completion of the Structured elements block.
* **FF08:** Malformed operation identifier.
* **OZ01:** Internal error in processing the transfer.

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH OUT"
  },
  "servers": [
    {
      "url": "https://accounts.onlyup.com.br/"
    }
  ],
  "paths": {
    "/api/v2/pix/payments/idempotencyKey/{idempotencyKey}": {
      "get": {
        "description": "",
        "operationId": "get_apiv2pixpaymentsidempotencyKey{idempotencyKey}",
        "responses": {
          "200": {
            "description": "200",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "data": {
                      "type": "object",
                      "properties": {
                        "id": {
                          "type": "integer",
                          "default": "0"
                        },
                        "idempotencyKey": {
                          "type": "string"
                        },
                        "endToEndId": {
                          "type": "string"
                        },
                        "pixKey": {
                          "type": "string"
                        },
                        "transactionType": {
                          "type": "string"
                        },
                        "status": {
                          "type": "string"
                        },
                        "errorCode": {
                          "type": "string"
                        },
                        "creditDebitType": {
                          "type": "string"
                        },
                        "localInstrument": {
                          "type": "string"
                        },
                        "createdAt": {
                          "type": "string"
                        },
                        "creditorAccount": {
                          "type": "object",
                          "properties": {
                            "ispb": {
                              "type": "string"
                            },
                            "issuer": {
                              "type": "string"
                            },
                            "number": {
                              "type": "string"
                            },
                            "accountType": {
                              "type": "string"
                            },
                            "document": {
                              "type": "string"
                            },
                            "name": {
                              "type": "string"
                            }
                          }
                        },
                        "debtorAccount": {
                          "type": "object",
                          "properties": {
                            "ispb": {
                              "type": "string"
                            },
                            "issuer": {
                              "type": "string"
                            },
                            "number": {
                              "type": "string"
                            },
                            "accountType": {
                              "type": "string"
                            },
                            "document": {
                              "type": "string"
                            },
                            "name": {
                              "type": "string"
                            }
                          }
                        },
                        "remittanceInformation": {
                          "type": "string"
                        },
                        "txId": {
                          "type": "string"
                        },
                        "payment": {
                          "type": "object",
                          "properties": {
                            "currency": {
                              "type": "string"
                            },
                            "amount": {
                              "type": "number"
                            }
                          }
                        },
                        "refunds": {
                          "type": "array",
                          "items": {
                            "properties": {
                              "endToEndId": {
                                "type": "string"
                              },
                              "status": {
                                "type": "string"
                              },
                              "errorCode": {
                                "type": "string"
                              },
                              "pixRefundAmount": {
                                "type": "object",
                                "properties": {
                                  "currency": {
                                    "type": "string"
                                  },
                                  "amount": {
                                    "type": "number",
                                    "default": "0"
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "429": {
            "description": "429",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "500": {
            "description": "500",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
            "name": "idempotencyKey",
            "schema": {
              "type": "string"
            },
            "required": true,
            "description": "Idempotency Key of the pix"
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
Initiate a payment by QR Code.

# Initiate a payment by QR Code.

This endpoint allows the request of a Pix payment order (cash-out) using data that follow the specifications of the Banco Central do Brasil and that can be expressed in a QRCode. This data is also known as 'Copy and paste', as it can be copied in text format and inserted as the creditor's identifier.

# Authorizations

> OAuth2 (pix.write)

## OAuth2: OAuth2

Flow type: clientCredentials

Token URL: /oauth/token

Required scopes: pix.write

Scopes:

* pix.read - Access to consult Pix
* pix.write - Access to make cashout Pix
* billets.read - Access to read billets
* billets.write - Access create billets
* webhook.read - Access to read webhooks
* webhook.write - Access to create webhooks
* transactions.read - Access to transactions
* account.read - Access to account infos
* internal-transfer.read - Access to read internal transfers
* internal-transfer.write - Access to create internal transfers

<br />

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH OUT"
  },
  "servers": [
    {
      "url": "https://accounts.onlyup.com.br/"
    }
  ],
  "paths": {
    "/api/v2/pix/payments/qrc": {
      "post": {
        "description": "",
        "responses": {
          "202": {
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "endToEndId": {
                      "type": "string",
                      "description": "The \"endToEndId\" field refers to a unique identifier that can be used to track and associate a specific transaction throughout the lifecycle of the operation. The term \"end-to-end\" pertains to the ability to trace the transaction from beginning to end, ensuring data integrity along the way."
                    },
                    "eventDate": {
                      "type": "string",
                      "description": "<date-time>"
                    },
                    "name1": {
                      "type": "integer",
                      "format": "int64"
                    },
                    "payment": {
                      "type": "object",
                      "properties": {
                        "currency": {
                          "type": "string",
                          "description": "Value: \"BRL\""
                        },
                        "amount": {
                          "type": "number",
                          "format": "double"
                        }
                      }
                    },
                    "type": {
                      "type": "string"
                    }
                  }
                },
                "examples": {
                  "Accepted": {
                    "summary": "Accepted",
                    "value": {
                      "endToEndId": "string",
                      "eventDate": "2019-08-24T14:15:22Z",
                      "id": 0,
                      "payment": {
                        "currency": "BRL",
                        "amount": 0.1
                      },
                      "type": "string"
                    }
                  }
                }
              }
            },
            "description": "Accepted"
          },
          "400": {
            "description": "Bad Request",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  },
                  "required": [
                    "type"
                  ]
                },
                "examples": {
                  "Bad Request": {
                    "summary": "Bad Request",
                    "value": {
                      "type": "string",
                      "title": "string",
                      "detail": "string",
                      "instance": "string"
                    }
                  }
                }
              }
            }
          },
          "401": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  },
                  "required": [
                    "type"
                  ]
                },
                "examples": {
                  "Unauthorized": {
                    "summary": "Unauthorized",
                    "value": {
                      "type": "string",
                      "title": "string",
                      "detail": "string",
                      "instance": "string"
                    }
                  }
                }
              }
            },
            "description": "Unauthorized"
          },
          "403": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  },
                  "required": [
                    "type"
                  ]
                },
                "examples": {
                  "Forbidden": {
                    "summary": "Forbidden",
                    "value": {
                      "type": "string",
                      "title": "string",
                      "detail": "string",
                      "instance": "string"
                    }
                  }
                }
              }
            },
            "description": "Forbidden"
          },
          "412": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  },
                  "required": [
                    "type"
                  ]
                },
                "examples": {
                  "Precondition Failed": {
                    "summary": "Precondition Failed",
                    "value": {
                      "type": "string",
                      "title": "string",
                      "detail": "string",
                      "instance": "string"
                    }
                  }
                }
              }
            },
            "description": "Precondition Failed"
          },
          "422": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  },
                  "required": [
                    "type"
                  ]
                },
                "examples": {
                  "Unprocessable Entity": {
                    "summary": "Unprocessable Entity",
                    "value": {
                      "type": "string",
                      "title": "string",
                      "detail": "string",
                      "instance": "string"
                    }
                  }
                }
              }
            },
            "description": "Unprocessable Entity"
          },
          "429": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  },
                  "required": [
                    "type"
                  ]
                },
                "examples": {
                  "Too Many Requests": {
                    "summary": "Too Many Requests",
                    "value": {
                      "type": "string",
                      "title": "string",
                      "detail": "string",
                      "instance": "string"
                    }
                  }
                }
              }
            },
            "description": "Too Many Requests"
          },
          "500": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  },
                  "required": [
                    "type"
                  ]
                },
                "examples": {
                  "Internal Server Error": {
                    "summary": "Internal Server Error",
                    "value": {
                      "type": "string",
                      "title": "string",
                      "detail": "string",
                      "instance": "string"
                    }
                  }
                }
              }
            },
            "description": "Internal Server Error"
          }
        },
        "parameters": [
          {
            "in": "header",
            "name": "x-idempotency-key",
            "schema": {
              "type": "string"
            },
            "required": true,
            "description": "[a-zA-Z0-9]{1,50}\n\nUnique request identifier to support idempotency."
          }
        ],
        "operationId": "post_api-v2-pix-payments-qrc",
        "requestBody": {
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "qrCode": {
                    "type": "string",
                    "default": "string"
                  },
                  "creditorDocument": {
                    "type": "string",
                    "default": "string"
                  },
                  "priority": {
                    "type": "string",
                    "description": "When the parameter is set to 'HIGH,' it is processed instantly (bypassing the processing queue). The value 'HIGH' is only allowed when the 'creditorDocument' field is defined.",
                    "enum": [
                      "HIGH",
                      "NORM"
                    ],
                    "default": "\"HIGH\""
                  },
                  "description": {
                    "type": "string",
                    "default": "string"
                  },
                  "paymentFlow": {
                    "type": "string",
                    "description": "Default value is INSTANT.\n\n- INSTANT - Payment will happen immediately\n- APPROVAL_REQUIRED - Payment only will happen when the order was approved.",
                    "enum": [
                      "INSTANT",
                      "APPROVAL_REQUIRED"
                    ],
                    "default": "\"INSTANT\""
                  },
                  "expiration": {
                    "type": "integer",
                    "format": "int64",
                    "description": "[ 1 .. 10800 ]\n\nMaximum time (seconds) that an operation can remain in the queue waiting for processing before being cancelled.",
                    "default": "600"
                  },
                  "payment": {
                    "type": "object",
                    "properties": {
                      "currency": {
                        "type": "string",
                        "description": "Value: \"BRL\"",
                        "default": "BRL"
                      },
                      "value": {
                        "type": "number",
                        "format": "double",
                        "default": "0.1"
                      }
                    },
                    "required": [
                      "value",
                      "currency"
                    ]
                  },
                  "ispbDeny": {
                    "type": "array",
                    "items": {
                      "type": "string"
                    },
                    "description": "(IspbDenyList)\n\nList of codes ISPB (Identificador de Sistema de Pagamentos Brasileiro) to which payments will not be allowed."
                  }
                },
                "required": [
                  "qrCode",
                  "payment"
                ]
              },
              "examples": {
                "New Example": {
                  "summary": "New Example",
                  "value": {
                    "qrCode": "string",
                    "creditorDocument": "string",
                    "priority": "HIGH",
                    "description": "string",
                    "paymentFlow": "INSTANT",
                    "expiration": 600,
                    "payment": {
                      "currency": "BRL",
                      "amount": 0.1
                    },
                    "ispbDeny": [
                      "string"
                    ]
                  }
                }
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
Configure Webhook

# Configure Webhook

These webhooks will be triggered when the cash-out operation has settled or been canceled.

### Webhooks – Accounts API

Pagstar Accounts API webhooks are always sent from fixed IP addresses:

`52.67.231.226`

`54.207.192.19`

This ensures that any integration can reliably identify the origin of the webhooks.

## Callback payload samples

```json
{
  "data": {
    "id": 0,
    "idempotencyKey": "string",
    "endToEndId": "string",
    "pixKey": "string",
    "transactionType": "PIX",
    "status": "CANCELED",
    "errorCode": "AB03",
    "creditDebitType": "CREDIT",
    "localInstrument": "MANU",
    "createdAt": "2019-08-24T14:15:22Z",
    "creditorAccount": {
      "ispb": "string",
      "issuer": "string",
      "number": "string",
      "accountType": "SLRY",
      "document": "string",
      "name": "string"
    },
    "debtorAccount": {
      "ispb": "string",
      "issuer": "string",
      "number": "string",
      "accountType": "SLRY",
      "document": "string",
      "name": "string"
    },
    "remittanceInformation": "string",
    "txId": "string",
    "payment": {
      "currency": "BRL",
      "amount": 0.1
    },
    "refunds": [
      {
        "endToEndId": "string",
        "status": "CANCELED",
        "errorCode": "AB03",
        "pixRefundAmount": {
          "currency": "BRL",
          "amount": 0.1
        }
      }
    ]
  },
  "type": "TRANSFER"
}
```

<br />

# OpenAPI definition

```json
{
  "openapi": "3.0.0",
  "info": {
    "version": "1.0.0",
    "title": "CASH OUT"
  },
  "servers": [
    {
      "url": "https://accounts.onlyup.com.br/"
    }
  ],
  "paths": {
    "/api/v2/webhooks/transfer": {
      "post": {
        "description": "",
        "operationId": "post_apiv2webhookstransfer",
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
                    "type": {
                      "type": "string"
                    },
                    "uri": {
                      "type": "string"
                    },
                    "enabled": {
                      "type": "boolean",
                      "default": "true"
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "409": {
            "description": "409",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "429": {
            "description": "429",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
                      "type": "string"
                    }
                  }
                }
              }
            }
          },
          "500": {
            "description": "500",
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
                    "detail": {
                      "type": "string"
                    },
                    "instance": {
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
                  "uri": {
                    "type": "string",
                    "description": "http://example.com/webhook",
                    "default": ""
                  },
                  "email": {
                    "type": "string",
                    "description": "Email to receive error notifications",
                    "default": ""
                  },
                  "method": {
                    "type": "string",
                    "description": "Method used by your webhook",
                    "default": "POST",
                    "enum": [
                      "POST",
                      "\"POST\"",
                      "\"GET\"",
                      "\"PUT\""
                    ]
                  },
                  "enabled": {
                    "type": "boolean",
                    "default": "true",
                    "description": "Enable webhook"
                  },
                  "pauseOnFail": {
                    "type": "boolean",
                    "description": "Pause webhook in case of failure",
                    "default": "false"
                  },
                  "headers": {
                    "type": "string",
                    "description": "Header for your webhook to use",
                    "format": "json"
                  }
                },
                "required": [
                  "uri",
                  "enabled"
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
Balde de Fichas

# Balde de Fichas

<Callout icon="📘">
  O que é o DICT?
</Callout>

O DICT (Diretório de Identificadores de Contas Transacionais) é um sistema gerenciado pelo Banco Central do Brasil, que armazena as informações das chaves Pix (como CPF, CNPJ, e-mail, número de telefone e chaves aleatórias) vinculadas às contas dos usuários.

Ele permite que instituições participantes realizem consultas seguras e centralizadas para identificar o banco e a conta associados a uma chave Pix, viabilizando transferências instantâneas com agilidade e precisão.

O sistema de consultas ao DICT utiliza o modelo de "baldes e fichas", conforme descrito no Manual Operacional do DICT (Seção 13 – Mecanismos de Prevenção a Ataques de Leitura).

<br />

<Callout icon="📘" theme="info">
  Por que existe o sistema de baldes e fichas?
</Callout>

Para garantir a segurança, estabilidade e privacidade das informações no DICT, o Banco Central implementou um mecanismo de controle de uso, conhecido como "balde de fichas".

**Esse sistema:**

* Evita sobrecarga nas consultas;
* Previne abusos e acessos em massa;
* Reduz o risco de tentativas de ataque (como leitura em massa de chaves);
* Protege os dados dos usuários.

O modelo está descrito na Seção 13 do Manual Operacional do DICT e se aplica a todas as instituições participantes

É essencial que os parceiros respeitem as regras estabelecidas pelo Banco Central, pois o descumprimento pode levar à suspensão temporária ou até ao bloqueio do acesso à API.

<br />

<Callout icon="📘" theme="info">
  Como funciona o balde de fichas
</Callout>

Cada CPF ou CNPJ possui um balde exclusivo de fichas, mesmo que haja múltiplas contas vinculadas. O balde define o número máximo de consultas que podem ser feitas em determinado período.

**Tipo de cliente/Capacidade do balde:** Pessoa Física (PF) até 10 fichas - Pessoa Jurídica (PJ) até 100 fichas

<br />

<Callout icon="📘" theme="info">
  Regras de consumo de fichas
</Callout>

**1 ficha é consumida por:** Consulta válida de chave Pix; Solicitação de cash-out (retirada de valores).

**20 fichas são consumidas se:** A consulta for inválida (chave inexistente ou incorreta); A transação não for concluída com sucesso. Se a transação for **bem-sucedida**, a ficha é **devolvida ao balde.**

<Callout icon="⚠️" theme="warn">
  **Atenção:** Ao atingir o limite de fichas disponíveis, o cliente não poderá realizar novas consultas ao DICT e receberá o erro: \[limite de consultas excedido | onz-0009]
</Callout>

<br />

<Callout icon="📘" theme="info">
  Reposição automática
</Callout>

A cada 1 minuto, são repostas 2 fichas automaticamente, até atingir o limite máximo do balde.

Esse mecanismo se aplica em todos os ambientes, inclusive em integrações com alto volume de requisições.

<br />

<Callout icon="👍" theme="okay">
  Dica importante
</Callout>

Se notar um consumo elevado de fichas, é recomendável:

* Revisar as requisições enviadas ao DICT;
* Evitar consultas com dados incorretos ou repetidos;
* Monitorar erros e logs de integração para identificar padrões de falhas.

<br />