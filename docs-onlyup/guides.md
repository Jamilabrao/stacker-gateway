Integration Guide
This page will help you get started with onlyup. You'll be up and running in a jiffy!

Welcome to the Onlyup Integration Guide!
Here you will find detailed information on how to integrate your application with our APIs, enabling you to offer innovative and secure financial services to your customers.

What is Onlyup?
Onlyup is a financial services platform that offers payment and billing solutions for businesses of all sizes. With Onlyup, you can integrate instant payments, transfers, and Pix billing into your application, allowing your customers to perform financial transactions quickly, securely, and efficiently.

Why Integrate with Onlyup?
Integrating with Onlyup offers a range of benefits, including:

Ease of Use: The Onlyup API is straightforward to integrate and easy to use, allowing you to quickly and efficiently add advanced financial functionalities to your application.
Security: Onlyup uses the highest security standards to protect your customers' financial transactions, ensuring that their data is always safe and secure.
Innovation: With Onlyup, you can offer your customers innovative and modern financial services, such as instant payments, Pix transfers, and much more.
Technical Support: Onlyup's technical support team is always available to assist with any questions or issues you may encounter during the integration process.
Cost-Effectiveness: Integrating with Onlyup is a cost-effective way to add advanced financial functionalities to your application without the need to develop and maintain your own payment infrastructure.
Flexibility: The Onlyup API is highly flexible and customizable, allowing you to tailor the financial services offered to meet the needs and requirements of your application.
Speed: With Onlyup, you can integrate instant payments, transfers, and Pix billing into your application in a matter of minutes, enabling you to quickly offer financial services to your customers.
Scalability: Onlyup is a scalable platform that can handle thousands of transactions per second, ensuring that your application can grow and expand without issues.
Reliability: Onlyup is a reliable and robust platform that provides high-quality and high-performance financial services for your customers.
Innovation: Onlyup is always seeking new technologies and solutions to enhance its services and provide the best possible experience for its customers.
Partnership: Onlyup values partnerships with its clients and is always willing to help and support the development of innovative and efficient financial solutions.
Getting Started
Credentials: Your credentials will be available after the contract is finalized and your Onlyup account is activated.
Documentation: Consult the Onlyup technical documentation for detailed information about the available endpoints, parameters, and methods for integration.
Technical Support: Contact the Onlyup technical support team for assistance and support during the integration process.
Production Environment: Once you have completed the integration and performed all necessary testing, you will be ready to start operating in production.
Next Steps
Now that you are aware of the benefits of integrating your application with Onlyup and the steps necessary to get started, go ahead and begin offering innovative and secure financial services to your customers. If you have any questions or need help during the integration process, do not hesitate to reach out to the Onlyup technical support team. We are here to help and ensure your integration is a success!

Thank you for choosing Onlyup as your financial services partner. We look forward to working with you and helping you achieve your business goals. Let’s get started! 🚀

Updated 5 months ago

Integration Suggestion
Did this page help you?

Integration Suggestion
Authentication
Is a digital certificate being used for the connection?
Is the token expiration time being observed?
Has a caching strategy been developed for token use within the validity period?
Does the caching strategy comply with the architecture of the platform being integrated (e.g., distributed architecture with multiple instances)?
Has token security been taken into account?
Are appropriate measures in place to handle possible authentication failures?
Webhook
Is the callback URL operating exclusively over the HTTPS protocol?
Have measures been defined to mitigate a potential DoS attack directed at the callback URL?
Are failures in the process being properly handled and reported?
Is there a monitoring and logging strategy in place?
Charges and Payments
Is the authorization token being retrieved from the cache?
Is a digital certificate being used for the connection?
Is the charge/payment status being validated?
Is the txId/e2eId being stored as a means of association?
Queries
Is the authorization token being retrieved from the cache?
Is a digital certificate being used for the connection?
Is there a refund in the transaction being queried?
Is the payment amount being validated?
Compliance
Is the token limit being respected?
Is the number of operations per minute within the expected range?
Is the number of Pix operation queries equal to the number of charges created?
Updated 5 months ago

Integration Guide
Integration with Postman

Integration with Postman

mdx

# Run in Postman

To speed up your development, we are sharing the Postman collection.

[<img src="https://run.pstmn.io/button.svg" alt="Run In Postman" style={{ width: "128px", height: "32px" }}>](https://drive.google.com/file/d/1IuOa9TBjPg6rVFU41fkvUwdIU6K9LNZ9/view?usp=sharing)

# MTLS

MTLS (Mutual Transport Layer Security), or Mutual TLS, is an extension of the TLS (Transport Layer Security) protocol that provides mutual authentication between clients and servers in secure communication over the Internet. MTLS adds an additional layer of security to TLS communication, ensuring that both the client and the server are authenticated before establishing a secure connection. This is especially useful in scenarios where the identity of the client is as important as that of the server, such as in online banking services.
**Important**: Both test and production certificates are self-signed certificates and this should be taken into consideration during the integration development process.

## Configuring MTLS Certificate

Here you can use Postman to make the requests presented in this documentation. The collection that will be provided will present all the endpoints, allowing you to use it easily and efficiently.

### Configuring Postman to Import Certificates

In Postman, access the settings, select the Certificates tab, and click on "Add certificate...";

![](https://files.readme.io/7b346174b9df17c0df3ed753c0254552efc623632f8ba9ead6231bbd64e03494-image.png)

Insert the test environment URL, and then:

* In the "CRT file" and "KEY file" section, click on "Select file" and choose the ".crt" and ".key" files that were extracted to the certificate directory.

![](https://files.readme.io/70c899c0adb37c11cc2019ff9f57a05f63e8252617ce8a242258e2931d650146-image.png)

Or

* In the "PFX file" section, click on "Select file" and select the ".pfx" file that was extracted to the certificate directory. In the "Passphrase" field, enter the password for the certificate provided in the email.
Updated 5 months ago

Integration Suggestion
Credentials
How to Generate My Credentials?
Access the Finance Onlyup
Go to "Settings" in the sidebar menu.
Use the API QRCodes or API Accounts to manage your credentials.

Updated 5 months ago

Integration with Postman
Authentication
Implementing caching for JWT tokens can be a valuable strategy for improving performance and reducing network traffic in systems that rely on token-based authentication. By temporarily storing access tokens in cache, clients can avoid the need to request new tokens for each API call, as long as the tokens remain within their validity period.

To ensure the security of cached tokens, it is essential to follow some best practices. First, JWT tokens should be securely stored on the partner's side, preventing unauthorized access to resources. Additionally, the credentials and certificates used to obtain the tokens must be adequately protected.

When choosing between local and shared cache, it is important to consider the specific requirements of the system, including security, scalability, and consistency. While local cache provides quick access, it is volatile and can be lost in the event of a system restart. On the other hand, distributed cache is suitable for distributed environments, ensuring consistency and availability of tokens throughout the environment.

❕
Important: Only 10 requests per minute are accepted.

When setting up a distributed caching system, it is crucial to implement adequate security measures to protect stored authorization tokens. This includes encrypting data both at rest and in transit, as well as monitoring for suspicious activities.

Furthermore, it is essential to implement an automatic token renewal mechanism to ensure that tokens are refreshed before they expire. This can be achieved through automatic re-authentication when a token is about to expire.

To address concurrency issues when accessing and updating authorization tokens in distributed cache, it is advisable to implement concurrency control mechanisms, such as locks, mutexes, atomic transactions, and version control. These mechanisms ensure data consistency in the cache and avoid issues like race conditions and data inconsistencies.

Access Token Request
👍
Remember that you must first configure the MTLS certificate.
Once the access token is obtained, you can use it to authenticate API calls during the validity period specified in the "expires_at" field of the response.

Request
cURL

curl --location '<https://api.pix.onlyup.com/oauth/token'>  
    --header 'Content-Type: application/x-www-form-urlencoded'  
    --data-urlencode 'client_id=\<your_client_id>'  
    --data-urlencode 'client_secret=\<your_client_secret>'  
    --data-urlencode 'grant_type=client_credentials' \\
Response
JSON

{  
  "access_token": "eyJhbGciOi…",  
  "expires_in": 300,  
  "refresh_expires_in": 0,  
  "token_type": "Bearer",  
  "not-before-policy": 1680810673,  
  "scope": "profile email qrcodes"  
}
Updated 5 months ago

Credentials
Webhook
Did this page help you?

Webhook
Webhooks are a powerful tool for receiving information about API events as they happen. An event, such as a payment being made or receiving a Pix, is an activity that occurs outside your system. With webhooks, you can create or configure integrations that allow notifications to be automatically sent to a specific endpoint whenever an event occurs.

Why Use Webhooks?
Resource Savings
Instead of your application having to make constant requests to check for updates (polling), webhooks send notifications only when a specific event occurs. This reduces bandwidth usage and processing, saving resources and improving the overall performance of the application. Resource savings can also lead to reduced operational costs and greater efficiency in infrastructure management.

Reliability
Webhooks provide a reliable way to ensure that important events are notified immediately. Our webhook system implements retry mechanisms, which increases the reliability of communication. This ensures that even in cases of temporary failures or interruptions, notifications will be retried until they are correctly received by the system.

Reduced Latency
By receiving notifications in real-time, the system can quickly respond to events, which reduces latency compared to polling-based approaches. This is particularly important for applications that require quick responses.

Better Scalability
Webhooks allow your system to react to events without overloading the API with polling requests. This can improve scalability by preventing the application from having to handle a large number of simultaneous requests to check for updates, allowing the system to focus on processing actual events as they occur.

How Can I Identify the Source of Webhook Triggers?
We use dynamic IPs to send our webhooks, meaning that the IP addresses may change with each sending. To ensure the security and proper identification of webhooks sent by our platform, we recommend that you configure a custom header in the webhook. Below is an example of how to configure it:

JSON

{  
  "uri": "{your_webhook_url}",  
  "enabled": true,  
  "headers": {  
    "headerName": "{your_header_name}"  
  }  
}
By configuring the webhook this way, whenever we send a webhook, we will include the header you have set, allowing the identification and authentication of the messages

Updated 5 months ago

Authentication
Related Topics on Pix


Related Topics on Pix
CPF
CPF (Cadastro de Pessoas Físicas) is a tax identification number used in Brazil for individuals. It is like a personal tax ID that every Brazilian citizen or foreign resident must have. It is used for various purposes, including financial transactions, opening bank accounts, paying taxes, and more. Example: 268.211.520-98 JSON Format: 26821152098 Format Validation: ^[0-9]{11}$

CNPJ
CNPJ (Cadastro Nacional da Pessoa Jurídica) is a tax identification number used in Brazil for companies and other legal entities. It is like CPF but for businesses. Every registered company in Brazil must have a CNPJ, which is used for commercial purposes such as opening bank accounts, issuing invoices, paying taxes, hiring employees, among others. Example: 58.255.572/0001-00 JSON Format: 58255572000100 Format Validation: ^[0-9]{14}$

Chave Pix
Chave Pix is like a "unique address" you can use to receive instant payments in Brazil quickly and easily. This key acts as a unique identifier linked to your bank account in Brazil. Even if you are in the USA, you can have a Brazilian bank account and use Pix. The Chave Pix can be associated with various types of information, such as CPF/CNPJ, phone number, email, or even a random key generated by the system. This variety allows you to choose the most convenient way to receive payments without needing to share sensitive banking information, like bank account and branch numbers. When someone makes a transfer to you, you simply provide your Chave Pix, whether it's CPF/CNPJ, phone, email, or random key. This greatly simplifies the payment process, as it eliminates the need to fill in various banking details, as was required previously.

Type of Pix Key	Description	Format Validation
CNPJ	Tax number of the business	^[0-9]{14}$
CPF	Tax identification number	^[0-9]{11}$
Phone Number	Phone number	^+[1-9][0-9]\d{14}$
Email	Email address	^[a-z0-9.!#$&'*+\/=?^_{|}~-]+@a-z0-9?(?:.a-z0-9?)*$
EVP (Random Key)	Random key (generated by the Central Bank of Brazil)	[0-9a-f]8-[0-9a-f]4-[0-9a-f]4-[0-9a-f]4-[0-9a-f]12$
TransactionId
This identifier serves to uniquely identify a specific charge. Therefore, the txid is used by the recipient user in their reconciliation processes of payments received via Pix. This identifier can be automatically generated by the Onlyup API (only for immediate charges) or can be provided by the partner. In this case, the partner must ensure that the txId value is unique for the receiver (CPF/CNPJ). The txid must have a minimum length of 26 characters and a maximum length of 35 characters. The accepted characters in this context are: A-Z, a-z, 0-9. Format Validation: ^[A-Za-z0-9]{35}$

Charge Status
The "status" field represents the status of the immediate payment charge and can assume the following states:

**ACTIVE: **This status indicates that the Pix charge is active and available for payment. The charge is in progress and can be fulfilled by the payer.
COMPLETED (final status): This status means that the Pix charge has been successfully completed. The payment transaction associated with the charge has been executed, and the funds have been transferred from the payer to the recipient.
REMOVED_BY_RECIPIENT (final status): This status indicates that the Pix charge was withdrawn or canceled by the recipient user (who requested the payment). This action can be initiated by the recipient for various reasons, such as canceling the transaction, needing to modify payment details, or other administrative reasons.
REMOVED_BY_PSP (final status): This status means that the Pix charge was withdrawn or canceled by Onlyup Finance. The removal of the charge by Onlyup Finance may occur for compliance reasons, technical issues, or other operational considerations (such as when expired, for example).
E2e (endToEndId)
The endToEndId (e2e) identification is a crucial component that ensures the traceability and integrity of the transaction. It is a unique identifier assigned to each payment transaction, allowing end-to-end tracking from the payer to the beneficiary. This identifier remains constant throughout the transaction's life cycle, from initiation to completion, and serves as a reference point for reconciliation and dispute resolution. The endToEndId is typically generated by the initiating party and included in the payment instruction. It may consist of various data elements, such as a combination of alphanumeric characters, transaction reference numbers, or any other identifiers specified by the payment system. This ID helps differentiate one transaction from another and prevents duplication or misinterpretation. Additionally, the endToEndId plays a crucial role in maintaining the integrity and security of transactions. It ensures that the payment reaches the intended recipient without alteration or interception by unauthorized parties. By including this identifier, both the sender and the recipient can verify the authenticity of the transaction and reconcile it with their respective records. Overall, the endToEndId in the Pix payment facilitates efficient transaction processing, increases transparency, and strengthens security measures, contributing to a reliable and continuous payment ecosystem.

Updated 5 months ago

Webhook
Payments

Payments
Practical Guide to Payment Methods
With the evolution of payment systems, different methods have been developed to facilitate financial transactions, each with its own particularities and advantages. In this guide, we will explore three widely used methods: Copy and Paste Payment, PIX Key Payment, and Bank Details Payment.

Copy and Paste
Copy and Paste payment in the Pix system is a practical and quick way to make transactions. It works through the use of a QR code that is converted into text, allowing the payer to copy this text and paste it into the specific field of their bank or financial institution app to complete the payment. This method is especially useful in situations where the user cannot scan the QR code, such as in online purchases or when using a device that does not support a camera.

PIX Key
PIX Key payment is one of the most popular and convenient ways to make transfers using the Pix system. The PIX Key is a unique identifier that the user associates with their bank account to facilitate transfers and receipts. The user can simply provide the PIX Key, which can be one of the following identifiers:

CPF/CNPJ: The CPF number (for individuals) or CNPJ (for companies).
Email: An email address associated with the account.
Phone number: The mobile number associated with the account.
Random key: A randomly generated alphanumeric code that contains no personal information.
Bank Details
In bank details payment, the payer does not use a PIX key, but instead uses the full bank account information of the recipient to complete the transfer. These details include account number, branch, account holder’s name, and document number.

PIX Payment Error Codes
Error Codes	Error Name	Description	Error Origin
AB03	AbortedSettlementTimeout	Transaction settlement aborted due to SPI timeout.	SPI
AB09	ErrorCreditorAgent	Transaction aborted due to error in the recipient user’s participant.	Recipient participant
AB11	TimeoutDebtorAgent	Timeout from the payment order issuer participant.	SPI
AC03	InvalidCreditorAccountNumber	Nonexistent or invalid branch and/or account number of the recipient.	Recipient participant
AC06	BlockedAccount	Recipient’s account is blocked.	Recipient participant
AC07	ClosedCreditorAccountNumber	Recipient’s account is closed.	Recipient participant
AC14	InvalidCreditorAccountType	Incorrect account type for the recipient.	Recipient participant
AG03	TransactionNotSupported	Transaction type not supported/authorized for the recipient's account (e.g., transfer to a salary account).	Recipient participant
AG12	NotAllowedBookTransfer	Payment/return orders in SPI between accounts of the same institution or using the same settlement participant are not allowed.	SPI
AG13	ForbiddenReturnPayment	It is not allowed to return a return of an instant payment.	SPI
AGNT	IncorrectAgent	Direct participant is not the settlement agent of the payer’s institution.	SPI
AM01	ZeroAmount	Instant payment order with a zero value.	SPI
AM02	NotAllowedAmount	Payment/return order exceeds the allowed limit for the recipient’s account type.	Recipient participant
AM04	InsufficientFunds	Insufficient balance in the payer participant’s PI account.	SPI
AM09	WrongAmount	Returned payment exceeds the amount of the original instant payment order.	Recipient participant
AM12	InvalidAmount	Mismatch between the sum of the valorDoDinheiroOuCompra block and the valor field.	SPI
AM18	InvalidNumberOfTransactions	Invalid number of transactions.	SPI
BE01	InconsistenWithEndCustomer	CPF/CNPJ of the recipient does not match the account holder.	Recipient participant
BE05	UnrecognisedInitiatingParty	Payment initiator’s CNPJ is not registered in the Pix arrangement.	SPI
BE17	InvalidCreditorIdentificationCode	QR Code rejected by the recipient’s institution.	Recipient participant
CH11	CreditorIdentifierIncorrect	Incorrect CPF/CNPJ of the recipient.	Recipient participant
CH16	ElementContentFormallyIncorrect	Incorrect or non-compliant message content.	SPI
DS04	OrderRejected	Order rejected by the recipient’s participant.	Recipient participant
DS0G	NotAllowedPayment	Participant signing the message is not authorized to operate on the debited PI account.	SPI
DS24	WaitingTimeExpired	Order rejected due to delay between the pain.013 and pacs.008 messages.	Recipient participant
DS27	UserNotYetActivated	Participant is not registered or has not started operation in SPI.	SPI
DT02	InvalidCreationDate	Invalid message date and time.	SPI
DT05	InvalidCutOffDate	Transaction exceeds the maximum return period defined by Pix.	Recipient participant
ED05	SettlementFailed	Error processing the instant payment (generic error).	SPI / Recipient participant
FF07	InvalidPurpose	Inconsistency between transaction purpose and the Structured block.	SPI
FF08	InvalidEndToEndId	Malformed operation identifier.	SPI
MD01	NoMandate	ISPB of the Pix Withdrawal or Pix Change facilitator is nonexistent.	SPI
OZ01	InsufficientBalance	Insufficient balance.	---
RC09	InvalidDebtorClearingSystemMemberIdentifier	ISPB of the payer's participant is invalid or nonexistent.	SPI
RC10	InvalidCreditorClearingSystemMemberIdentifier	ISPB of the recipient's participant is invalid or nonexistent.	SPI
RR04	Regulatory Reason	Payment order where the payer is sanctioned by UN Security Council resolution. (If the recipient is sanctioned, the order should not be rejected.)	Recipient participant
SL02	SpecificServiceOfferedByCreditorAgent	Original transaction not related to Pix Withdrawal or Pix Change services.	Recipient participant
OZ02	Processing Error	Processing error.	---

Updated 5 months ago

Related Topics on Pix
Special Refund Mechanism (MED)
Special Refund Mechanism (MED)
The Special Refund Mechanism (MED) is a feature designed to protect customers in cases of fraud, scams, or operational errors involving the use of Pix. Financial institutions are required to adhere to the MED, which operates as a set of rules and procedures to ensure user safety.

In addition to fraud, the MED also allows the recovery of funds that were wrongly credited, such as in cases of duplicate payments, which commonly result from operational failures of financial institutions.

Situations Where the MED Applies
Suspected fraud or scam: The MED can be requested even in cases where no physical force was used.
Unauthorized transactions: If third parties, such as hackers or individuals with access to your device, perform unauthorized transactions.
**Operational failures: **Errors like duplicate payments that are the responsibility of the financial institution.
Beneficiary involved in illicit activities: When the recipient is involved in criminal activity without the payer’s knowledge.
How to Request the MED
If you are a victim of fraud, scam, or operational error, the MED request process must be initiated within 80 days from the date of the Pix transaction. The process follows these steps:

1. Filing the Complaint
You must file a refund request with your financial institution, which will then assess whether the case qualifies for the MED.

2. Blocking the Funds
If the institution determines the request is valid, the transferred amount will be blocked in the recipient’s account.

3. Review and Resolution
The case will be reviewed within 7 calendar days, with:

**5 days **for the recipient to present a defense, and
2 days for the institution to evaluate the case.
If no fraud is found: The funds will be unblocked. If fraud is confirmed: The full or partial amount will be refunded to you within 96 hours after the decision.

Operational Error
In cases of operational error, such as a duplicate transaction, the institution has up to 24 hours to refund the money after confirming the mistake.

To activate the MED and recover the funds, you must contact the bank responsible for the transaction using the communication channels provided by the institution.

Updated 5 months ago

Payments
F.A.Q.

an I Query Pix Keys?
According to BCB Resolution No. 1, dated August 12, 2020, in Section II - "Access," there are specific rules regarding who can access the information in the Directory of Transactional Account Identifiers (DICT). This resolution clarifies that access to information can be obtained by direct and indirect participants. The BCB clearly states who can access the environment, and anything not specified in the regulation cannot be executed. Therefore, those who do not qualify as direct or indirect participants do not have access to this information.

What is the MED (Special Mechanism for Refund)?
The MED (Special Mechanism for Refund) is a feature that clients can request to ensure the return of money in cases of fraud, scams, or operational errors specifically involving the use of Pix. Financial institutions are required to adhere to the MED — which serves as a guide with rules and conduct to ensure user security.

The MED also allows you to reclaim your money in the event of an undue credit caused by an operational failure of the financial institution. Duplicate payments are the most common example that fits this type of situation.

With the MED, it is possible to do so within 80 days from the date the Pix transaction was made.

Cases in which the MED applies: Suspected fraud, scams, and similar situations — regardless of whether or not violence was involved. Unauthorized transactions by the account holder involving third parties, such as hackers or someone who stole your device and accessed your money, for example. Operational failures caused by the financial institution, such as duplicate payments. If the beneficiary of the Pix transaction is a criminal organization or involved in illegal activities without the sender's knowledge.

