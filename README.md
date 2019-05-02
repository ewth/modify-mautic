# ModifyMautic

This is a simple script created out of frustration at being unable to access the Mautic dashboard in an installation
long since forgotten.

All I had was access to the database, and needed to modify some of the email content located within. As they were stored
in serialised strings, it was something of a process to manually retrieve, unserialise, modify, reserialise, store.
As any programmer would understand, an better process was required.

Excuse the horrible aesthetics, this was created rather quickly to get the job done &mdash; function over form.

**Only** works with MySQL.

## Configuration
Copy `config.php.example` to `config.php` and modify it for your details.

## Usage
Visit `http://your-site/modify-mautic/` in your browser. The rest is self-explanatory.

# Warning
This will make irreversible changes to your database. I would not recommend using it on a production database... or anywhere else.
