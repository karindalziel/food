# Food Tracking App Instructions

## About
Built almost entirely using Claude Code, no warranty expressed or implied.

## Application design
The goal of this app is to duplicate the basic functionality of an airtable base with a PHP/SQL Lite application so I can put on my webserver. 

No account management is needed at this time, due to placing in a passworded folder. 

The application should have a clean and modern interface, work well on a mobile phone, and should prioritize ease of data entry. 

The application should have the ability to import and export data as a csv. 

The application should be allow entry of new foods and meals with values or without, but alert the user for unnotified users. 

All users will share meals, foods, and the API key. There is no need to authenticate between users. 

## General Notes about app design

- Add comments and organize for simplicity.
- Warn for database changes that will cause migrations, but do not automatically carry out migrations.
- Make app accessible, targeting WCAG 2.2
- Use secure app design, with the exception of authentication and users at this time. 
- Do not overwrite human changes, but raise a warning if they cause issues or seem incongruous. 

