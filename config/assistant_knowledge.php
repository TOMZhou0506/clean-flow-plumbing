<?php
declare(strict_types=1);

/**
 * What the website assistant is allowed to know.
 *
 * Every answer here was written by us from the site itself. That is the point:
 * US 10.1 says the assistant must not invent business information, and the only
 * reliable way to hold a language model to that is to never let it answer from
 * its own memory. It is given a few of these entries as context and told to
 * answer from them or say it cannot. If nothing here matches the question, the
 * model is not called at all.
 *
 * `audience` is the access control. A customer's question can only ever be
 * matched against entries that list an audience they hold, so no phrasing of a
 * question can pull an internal answer into the prompt. Filtering happens before
 * the model sees anything, rather than being an instruction the model is asked
 * to respect.
 *
 *   public   - anyone, including signed-out visitors
 *   customer - signed-in customers
 *
 * There are no office audiences. The assistant is a public and customer
 * feature; admins, plumbers and the manager do not get it at all, and the
 * check that enforces that is Audience::isAvailableTo(). Nothing in this file
 * should ever describe internal process again, because the people it would be
 * written for cannot reach it.
 *
 * `link` is optional and gives the assistant somewhere to send people, which is
 * what US 10.2 asks for when it says the customer should be directed to the
 * relevant contact or request option.
 *
 * Adding an entry is a normal code review. That is deliberate: it means nobody
 * can quietly widen what the assistant will say.
 *
 * @var array<int, array{id:string, audience:list<string>, title:string, keywords:list<string>, answer:string, link?:array{label:string, url:string}}>
 */
return [
    // ---------------------------------------------------------------- public

    [
        'id' => 'services-offered',
        'audience' => ['public', 'customer'],
        'title' => 'What Clean Flow does',
        'keywords' => [
            'service', 'services', 'what do you do', 'offer', 'pipe', 'pipes',
            'drain', 'drainage', 'hot water', 'leak', 'leak detection',
            'maintenance', 'blocked', 'tap', 'plumbing',
        ],
        'answer' => 'Clean Flow Plumbing Services handles pipe repairs, drainage, '
            . 'hot water installation, leak detection and routine maintenance, for '
            . 'homes and businesses across Victoria.',
    ],
    [
        'id' => 'service-area',
        'audience' => ['public', 'customer'],
        'title' => 'Where Clean Flow works',
        'keywords' => [
            'where', 'area', 'areas', 'location', 'suburb', 'melbourne',
            'regional', 'victoria', 'do you come to', 'service area',
            // "What areas do you service" was landing on the services entry,
            // because 'service' there is one word and 'areas' here is one
            // word, and the tie went on phrase length. These say plainly that
            // the question is about geography.
            'what areas', 'which areas', 'areas do you', 'come out to',
        ],
        'answer' => 'Clean Flow covers Melbourne and regional Victoria.',
    ],
    [
        'id' => 'pricing',
        'audience' => ['public', 'customer'],
        'title' => 'How pricing works',
        'keywords' => [
            'cost', 'price', 'pricing', 'quote', 'how much', 'charge', 'fee',
            'expensive', 'estimate',
        ],
        // Deliberately does not quote a figure. We do not publish a price list,
        // so the assistant has nothing to repeat and cannot be led into
        // inventing one.
        'answer' => 'You get a fixed-price quote before any work starts, so you '
            . 'will know the cost before anyone begins. Prices depend on the job, '
            . 'so the quote comes after we understand what is needed.',
    ],
    [
        'id' => 'contact-us',
        'audience' => ['public', 'customer'],
        'title' => 'How to get in touch',
        'keywords' => [
            'contact', 'phone', 'call', 'email', 'speak to someone', 'talk to',
            'reach you', 'enquiry', 'enquire', 'question',
        ],
        'answer' => 'You can call (03) 9000 0000, or send a message through the '
            . 'contact form and someone will get back to you.',
        'link' => ['label' => 'Contact us', 'url' => '/contacts/add'],
    ],
    [
        'id' => 'how-it-works',
        'audience' => ['public', 'customer'],
        'title' => 'What happens after you log a job',
        'keywords' => [
            'how it works', 'what happens', 'process', 'next', 'after i submit',
            'steps', 'approved', 'scheduled',
        ],
        'answer' => 'You log the job online, we run a compliance check, the job is '
            . 'approved and scheduled, and then a plumber completes the work. You '
            . 'can see the status of your request online rather than having to ring '
            . 'and ask.',
    ],

    // -------------------------------------------- job requests (US 10.2 core)

    [
        'id' => 'job-request-where',
        'audience' => ['public', 'customer'],
        'title' => 'Where to submit a job request',
        'keywords' => [
            'job request', 'request service', 'book', 'booking', 'submit a job',
            'request a job', 'new request', 'request form', 'where do i',
            'how do i request', 'arrange', 'appointment',
        ],
        'answer' => 'Use the "Request service" button in the menu, which opens the '
            . 'job request form. You need a customer account first, because the '
            . 'request is attached to your account so you can track it afterwards. '
            . 'If you do not have one yet you can create one in a minute, then come '
            . 'straight back to the form.',
        'link' => ['label' => 'Request service', 'url' => '/job-requests/add'],
    ],
    [
        'id' => 'job-request-what-you-need',
        'audience' => ['public', 'customer'],
        'title' => 'What the job request form asks for',
        'keywords' => [
            'what information', 'what do i need', 'details', 'fields', 'required',
            // "Fill out" is at least as common as "fill in" and was missing.
            'fill in', 'fill out', 'what should i include', 'what do you need from me',
            'information needed', 'prepare',
        ],
        'answer' => 'The form asks for the address the work is at (street address, '
            . 'suburb, state and postcode), a description of the problem, the date '
            . 'and time that suits you, a contact phone number, and any access '
            . 'instructions such as which gate to use or where to park. The more '
            . 'specific the description, the better the quote will be.',
        'link' => ['label' => 'Request service', 'url' => '/job-requests/add'],
    ],
    [
        'id' => 'job-request-assistant-cannot-submit',
        'audience' => ['public', 'customer'],
        'title' => 'The assistant cannot submit the form for you',
        'keywords' => [
            'submit for me', 'do it for me', 'fill it in for me', 'book it for me',
            'can you submit', 'can you send', 'on my behalf', 'you do it',
        ],
        // US 10.2 is explicit that the assistant must not submit or alter a
        // request. It has no write access at all, so this is a description of
        // the system rather than a promise about behaviour.
        'answer' => 'I cannot submit or change a request for you. I can only explain '
            . 'how it works and point you at the form, so everything that reaches '
            . 'Clean Flow is something you entered and sent yourself.',
        'link' => ['label' => 'Request service', 'url' => '/job-requests/add'],
    ],
    [
        'id' => 'job-request-track',
        'audience' => ['customer'],
        'title' => 'Checking your own requests',
        'keywords' => [
            'my requests', 'my jobs', 'track', 'status', 'progress', 'where is my',
            'what is happening with', 'check my request', 'reference',
        ],
        'answer' => '"My job requests" in the menu lists everything you have sent, '
            . 'with a reference number and the current status of each one. Open a '
            . 'request to see the full details you submitted.',
        'link' => ['label' => 'My job requests', 'url' => '/job-requests'],
    ],
    [
        'id' => 'account-create',
        'audience' => ['public'],
        'title' => 'Creating a customer account',
        'keywords' => [
            'account', 'sign up', 'register', 'create account', 'join',
            'new customer', 'log in', 'login', 'sign in',
        ],
        'answer' => 'Use "Create account" in the menu to register, then "Log in" to '
            . 'sign in. One sign-in page covers both customers and Clean Flow staff, '
            . 'so pick the Customer option when you get there.',
        'link' => ['label' => 'Create account', 'url' => '/customers/register'],
    ],
];
