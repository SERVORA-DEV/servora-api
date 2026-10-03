<?php

// The Terms of Service and Privacy Policy, served to the web app and the
// mobile app by GET /api/legal (LegalController) so both always show the same
// text, and stamped on each account at sign-up (users.terms_version).
//
// DRAFT — written from how the product works, not by a lawyer. Have it
// reviewed before launch. Text in [square brackets] is a decision or detail
// that still has to be filled in.
//
// When the wording changes in a way people should re-read, bump `version`
// (and `effective_date`): new sign-ups are recorded against the new version.
//
// Placeholders {operator}, {address} and {email} are replaced with the
// values below. A body entry that is a string is a paragraph; one that is an
// array is a bulleted list.

return [

    'version' => '2026-10-02',
    'effective_date' => '2026-10-02',

    // Who runs Servora. Set these in .env before launch.
    'operator' => [
        'name' => env('LEGAL_OPERATOR_NAME', '[Company name]'),
        'address' => env('LEGAL_OPERATOR_ADDRESS', '[Business address]'),
        'email' => env('LEGAL_CONTACT_EMAIL', '[Contact email]'),
    ],

    'documents' => [

        // ─────────────────────────────────────────────────────────────────
        'terms' => [
            'title' => 'Terms of Service',
            'intro' => 'These terms are the agreement between you and {operator} ("Servora", "we", "us") for using the Servora web app, the Servora mobile app and related services. By creating an account or using Servora, you agree to them. If you do not agree, please do not use Servora.',
            'sections' => [
                [
                    'heading' => 'What Servora is',
                    'body' => [
                        'Servora is a platform for spa businesses and their clients. Spa owners and their staff use the web app to manage branches, services, staff, appointments, walk-in queues, billing and customer programs. Clients use the mobile app to find verified spas, book services, follow the queue and see their receipts and rewards.',
                        'Servora provides the software. The spa provides the treatment. We are not a party to the services a spa provides to its clients.',
                    ],
                ],
                [
                    'heading' => 'Your account',
                    'body' => [
                        [
                            'You must give accurate information when you sign up and keep it up to date.',
                            'You must be at least 18 years old to create an account. [Confirm the minimum age for client accounts.]',
                            'You must verify your email address before you can sign in.',
                            'Keep your password private. You are responsible for what is done through your account. Tell us at {email} if you think someone else has used it.',
                            'One person per account. Do not share a login; owners can create separate accounts for their managers and front-desk staff.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Spa owners: verification',
                    'body' => [
                        'Before a business can use Servora\'s modules, the owner must verify their identity and the business. This includes a government-issued ID, a face scan to confirm the ID belongs to you, the business\'s DTI Business Name Certificate, and each branch\'s mayor\'s permit.',
                        'You confirm that the documents you submit are genuine, belong to you or your business, and are current. We may approve, reject or ask you to resubmit them, and we may suspend a business or branch whose documents are false, expired or no longer valid.',
                        'You are responsible for the accounts you create for your staff and for what they do in your business\'s workspace.',
                    ],
                ],
                [
                    'heading' => 'Spa owners: plans and payment',
                    'body' => [
                        [
                            'Access to Servora\'s modules requires an active subscription plan. Each plan sets how many branches and user accounts you may have and which features are included. Current plans and prices are shown on the pricing page.',
                            'Plans are billed monthly or yearly, in advance, through our payment provider. Prices are in Philippine pesos.',
                            'You may turn automatic renewal on or off. With it on, your saved payment method is charged when the plan is due.',
                            'If a payment is missed, you keep access for a short grace period set by us. After that, access to the modules is paused until you renew.',
                            'For a limited period after you subscribe or renew, you may upgrade or downgrade your plan. An upgrade is charged the difference and applies immediately. A downgrade applies from your next due date. After that period, your plan stays as it is until its due date, when you may choose any plan.',
                            'Payments are not refunded, and we do not offer cancellation with a refund for the unused part of a term. [Confirm the refund position with counsel.]',
                            'If your business is over the limits of the plan you choose, you may need to reduce branches or accounts before you can renew on it.',
                            'We may change plans and prices. A change applies to you from your next term, and we will tell you before then.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Clients: bookings and visits',
                    'body' => [
                        [
                            'A booking made through Servora is an agreement between you and the spa. The spa sets its own services, prices, opening hours, booking rules and policies.',
                            'A spa may reschedule or cancel a booking. You will be notified in the app when it does. You may reschedule or cancel a booking that has not started, subject to the spa\'s rules.',
                            'Walk-in queue numbers and waiting times shown in the app are estimates.',
                            'Servora does not take payment from clients in the app. You pay the spa directly, using the methods the spa accepts. Receipts the spa records appear in your account.',
                            'Questions or complaints about a treatment, a price or a refund from a spa should be raised with that spa.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Loyalty points, vouchers, discounts and memberships',
                    'body' => [
                        'These programs are created and run by each spa, not by Servora. The spa decides how points are earned and what they are worth, when they expire, and the terms of its vouchers, discounts and memberships. Points and vouchers have no cash value, cannot be transferred, and are honoured only by the spa that issued them.',
                    ],
                ],
                [
                    'heading' => 'Listings and reviews',
                    'body' => [
                        [
                            'A verified branch may be shown to clients in the mobile app with its photos, services, prices, promotions and reviews. The spa is responsible for keeping that information accurate and for having the right to use the photos it uploads.',
                            'Reviews must be about a real visit and must not be abusive, misleading or unrelated to the visit. A spa may hide a review that breaks these rules, and so may we.',
                            'By posting a review or uploading content, you allow us to display it in Servora.',
                        ],
                    ],
                ],
                [
                    'heading' => 'What you must not do',
                    'body' => [
                        [
                            'Use Servora for anything unlawful, or to offer or request unlawful services.',
                            'Submit false information or documents, or pretend to be someone else.',
                            'Try to access accounts, businesses or data that are not yours.',
                            'Interfere with, overload, probe or reverse-engineer the service.',
                            'Copy or resell Servora, or use it to build a competing service.',
                            'Upload content that is abusive, obscene, or infringes someone else\'s rights.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Data that businesses put into Servora',
                    'body' => [
                        'A spa business decides what staff and client records it keeps in Servora and is responsible for having the right to collect and use that information. We process it on the business\'s behalf to provide the service. How we handle personal information is explained in the Privacy Policy.',
                    ],
                ],
                [
                    'heading' => 'Suspension and closing an account',
                    'body' => [
                        [
                            'We may suspend or close an account, business or branch that breaks these terms, submits false documents, or puts other users or the service at risk.',
                            'Clients can delete their account from the mobile app. Owners who want to close their business account can write to {email}.',
                            'Closing an account does not cancel amounts already due, and does not entitle you to a refund.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Availability and changes to the service',
                    'body' => [
                        'We work to keep Servora available and your data safe, but we do not promise that it will be uninterrupted or free of errors. We may change, add or remove features. We will try to give notice of changes that significantly affect how you use Servora.',
                    ],
                ],
                [
                    'heading' => 'Our responsibility to you',
                    'body' => [
                        'Servora is provided "as is". To the extent the law allows, we are not liable for the services, conduct or content of spas, clients or other users; for lost profits, lost revenue or lost data; or for indirect or consequential loss. To the extent the law allows, our total liability to you for any claim about the service is limited to the amount you paid us in the [three] months before the claim arose. Nothing in these terms limits liability that cannot be limited under Philippine law. [Have counsel confirm this section.]',
                    ],
                ],
                [
                    'heading' => 'Governing law',
                    'body' => [
                        'These terms are governed by the laws of the Republic of the Philippines. Disputes will be brought before the proper courts of [city, Philippines].',
                    ],
                ],
                [
                    'heading' => 'Changes to these terms',
                    'body' => [
                        'We may update these terms. The date at the top shows when they last changed. If a change is significant, we will tell you in the app or by email. Continuing to use Servora after a change takes effect means you accept the updated terms.',
                    ],
                ],
                [
                    'heading' => 'Contact',
                    'body' => [
                        '{operator}, {address}. Email: {email}.',
                    ],
                ],
            ],
        ],

        // ─────────────────────────────────────────────────────────────────
        'privacy' => [
            'title' => 'Privacy Policy',
            'intro' => 'This policy explains what personal information {operator} ("Servora", "we", "us") collects through the Servora web app and mobile app, why, who it is shared with, and the choices you have. We handle personal information in line with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173).',
            'sections' => [
                [
                    'heading' => 'Information we collect',
                    'body' => [
                        'From everyone with an account:',
                        [
                            'Your email address and password (the password is stored in hashed form, never in readable text).',
                            'Profile details you add, such as your name, phone number, gender, date of birth and photo.',
                            'Sign-in records: the date and time, device and browser type, and IP address of each sign-in, and your active sessions.',
                            'If you choose "Continue with Google", the email address Google confirms for you.',
                        ],
                        'From spa owners, for verification:',
                        [
                            'A government-issued ID (front and back) and a face scan taken in the app to confirm the ID is yours.',
                            'Business documents: the DTI Business Name Certificate and each branch\'s mayor\'s permit, with the business name, address and location.',
                        ],
                        'From spa businesses, as they use Servora:',
                        [
                            'Staff records: names, contact details, roles, schedules, attendance, the services each staff member performed and their commission.',
                            'Client records the spa keeps: names, contact details, appointments, bills, payments recorded and program balances.',
                            'Subscription and billing records for the business\'s plan. Card and e-wallet details are handled by our payment provider; we do not store full card numbers.',
                        ],
                        'From clients using the mobile app:',
                        [
                            'Your bookings, queue status, receipts, reviews, and your points, vouchers and memberships with each spa.',
                            'Your location, only while you use the app and only if you allow it, to show spas near you. It is not shared with spas.',
                        ],
                    ],
                ],
                [
                    'heading' => 'Why we use it',
                    'body' => [
                        [
                            'To create and secure your account, including email verification and, where enabled, two-factor sign-in.',
                            'To confirm that a spa business and its owner are real before it can operate on Servora.',
                            'To run the features you use: bookings, queues, billing, staff management, reports and customer programs.',
                            'To process subscription payments and send receipts and reminders.',
                            'To send service messages, such as verification emails, booking updates and security alerts.',
                            'To keep an audit trail of important actions, prevent fraud and abuse, and fix problems.',
                            'To meet legal obligations.',
                        ],
                        'We rely on your consent, on what is needed to provide the service you asked for, on our legitimate interest in keeping Servora safe and working, and on legal requirements, as the case may be.',
                    ],
                ],
                [
                    'heading' => 'Who we share it with',
                    'body' => [
                        [
                            'Spas you book with: when a client books, the spa sees the client\'s name and phone number and the booking details, so it can serve and contact the client.',
                            'Staff of the same business: owners, managers and front-desk staff see the records of their own business and branches, according to the permissions the owner sets.',
                            'Service providers that process data for us: a payment provider (Xendit) for subscription payments, a file-storage provider (Cloudinary) for uploaded documents and images, an email delivery provider for emails, and our hosting and database providers.',
                            'Map services: showing maps and looking up addresses sends map requests to OpenStreetMap-based services.',
                            'Authorities, when the law requires it.',
                        ],
                        'We do not sell personal information. Some providers may store or process data outside the Philippines; where they do, we require them to protect it.',
                    ],
                ],
                [
                    'heading' => 'Verification documents',
                    'body' => [
                        'IDs, face scans and business documents are used only to verify the owner and the business. They are stored privately and can be opened only by the owner who submitted them and by Servora administrators who review verifications.',
                    ],
                ],
                [
                    'heading' => 'How long we keep it',
                    'body' => [
                        'We keep personal information for as long as your account or your business\'s account is active, and afterwards for as long as needed for legal, tax, accounting and dispute purposes. [Set specific retention periods for verification documents, billing records and sign-in logs.]',
                        'When a client deletes their account in the mobile app, their sign-in details are anonymised and the account is deactivated. Records a spa needs to keep, such as past bills, remain with that spa.',
                    ],
                ],
                [
                    'heading' => 'How we protect it',
                    'body' => [
                        [
                            'Passwords are hashed.',
                            'Connections to Servora are encrypted in production.',
                            'Access inside a business is limited by role and permission. What Servora administrators can do is limited by permission, and their actions are recorded in an audit log.',
                            'Administrators can protect their accounts with two-factor authentication.',
                            'Repeated sign-in and verification attempts are rate-limited.',
                        ],
                        'No system is completely secure. If a breach affects your personal information, we will notify you and the National Privacy Commission as the law requires.',
                    ],
                ],
                [
                    'heading' => 'Your rights',
                    'body' => [
                        'Under the Data Privacy Act you have the right to be informed, to access your personal information, to correct it, to object to or withdraw consent for its processing, to have it erased or blocked where the law allows, to receive a copy in a portable format, and to complain to the National Privacy Commission.',
                        'You can see and change most of your details in your profile. For anything else, write to {email}. If your information was entered by a spa (for example as its client or staff member), we may refer your request to that spa, which decides how its records are kept.',
                    ],
                ],
                [
                    'heading' => 'Children',
                    'body' => [
                        'Servora is not intended for children. We do not knowingly collect personal information from anyone under 18. [Confirm the minimum age for client accounts.]',
                    ],
                ],
                [
                    'heading' => 'Cookies and device storage',
                    'body' => [
                        'The web app uses a cookie to keep you signed in and small amounts of browser storage to remember preferences such as theme and table view. The mobile app stores your sign-in and preferences on your device. We do not use advertising cookies.',
                    ],
                ],
                [
                    'heading' => 'Changes to this policy',
                    'body' => [
                        'We may update this policy. The date at the top shows when it last changed. If a change is significant, we will tell you in the app or by email.',
                    ],
                ],
                [
                    'heading' => 'Contact',
                    'body' => [
                        'For privacy questions or requests, contact our Data Protection Officer: [DPO name], {operator}, {address}. Email: {email}.',
                    ],
                ],
            ],
        ],
    ],
];
