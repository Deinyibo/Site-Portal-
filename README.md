============================================================
IRIEBE TOWN SECONDARY SCHOOL (UBE) — WEBSITE README
============================================================
A complete school website with an admin dashboard that lets you
change the words on the site without touching any code.

Built by New Technologies.

------------------------------------------------------------
1. WHAT IS IN THIS FOLDER
------------------------------------------------------------
  index.html    The website itself (the homepage).
  styles.css    All the styling — colors, layout, fonts.
  script.js     The behaviour — slideshow, menus, counters,
                FAQ, contact form, and content loading.
  admin.html    The admin dashboard (edit text, no coding).
  README.txt    This file.
  images/       A folder YOU create — holds your pictures.

Folder should look like this:

  school-website/
  ├── index.html
  ├── styles.css
  ├── script.js
  ├── admin.html
  ├── README.txt
  └── images/
      ├── school-logo.png
      └── new-tech-logo.png

------------------------------------------------------------
2. VIEWING THE WEBSITE
------------------------------------------------------------
- On your computer: double-click index.html. It opens in your
  browser. That is the whole website.
- All four files MUST stay in the same folder. If styles.css or
  script.js are moved, the site loses its design and behaviour.

------------------------------------------------------------
3. ADDING THE SCHOOL LOGO (important)
------------------------------------------------------------
1. Create a folder named  images  next to index.html.
2. Put the school's logo inside it and name it exactly:
       school-logo.png
3. Put the New Technologies logo inside it and name it exactly:
       new-tech-logo.png
If the files are missing or named differently, a green
placeholder shows instead — check the names and the folder.

------------------------------------------------------------
4. USING THE ADMIN DASHBOARD
------------------------------------------------------------
1. Double-click admin.html — it opens in your browser.
2. Sign in with the passcode:   iriebe2026
3. You will see simple boxes for every editable text on the
   website: school name, headline, red notice strip, About
   paragraphs, Head Teacher's welcome and name, the 3 news
   headlines, the pink admission banner, and contact details.
4. Type your changes, then press  SAVE CHANGES  at the bottom.
   - The website updates instantly IN THAT BROWSER.
   - Open index.html (or refresh it) to see the changes.

5. TO MAKE CHANGES APPEAR FOR EVERY VISITOR (when hosted):
   a. After saving, scroll to "Backup & Restore".
   b. Press  DOWNLOAD content.json .
   c. Upload that content.json file to your web host, into the
      SAME folder as index.html.
   - Every visitor will now see your new wording.

6. RESET ALL CHANGES wipes everything saved on that browser and
   restores the original wording.

NOTE: Changes saved in the dashboard live in the browser's own
storage. They show on that computer immediately; content.json
is what publishes them for everyone else.

------------------------------------------------------------
5. PUTTING THE WEBSITE ONLINE (HOSTING)
------------------------------------------------------------
Any static web host works — no database or special server needed.
Simple free options:

  A) Netlify (easiest)
     1. Go to https://www.netlify.com and sign up (free).
     2. Drag your whole school-website folder onto the
        "Add new site > Deploy manually" area.
     3. Netlify gives you a live web address immediately.

  B) Vercel  — https://vercel.com — same idea: sign up,
     "Add New Project", upload the folder.

  C) GitHub Pages — for users comfortable with GitHub:
     push the folder to a repository, then enable
     Settings > Pages on the main branch.

  D) Normal cPanel hosting — upload ALL files (including
     the images folder) into public_html with the File
     Manager or FTP.

After going live, remember: dashboard changes must be published
by downloading content.json and uploading it to the host.

------------------------------------------------------------
6. THINGS YOU SHOULD EDIT (PLACEHOLDERS TO REPLACE)
------------------------------------------------------------
Do these through the admin dashboard (no code editing):
  - Phone number: currently the placeholder +234 800 000 0000
  - Email:        currently info@iriebetownsec.sch.ng
  - Head Teacher's name (currently a placeholder name)
  - Notice strip / admission banner text each term
  - News headlines as events happen

Optional, in code (or ask your developer):
  - The 4 statistics (1,200+ students, 45+ staff, 18 subjects,
    100% free tuition) — in index.html, search for "data-count".
  - Photos: the site currently loads sample school photos from
    the internet. To use real school photos, put them in the
    images folder and replace the https:// image addresses in
    index.html with  images/your-photo.jpg .
  - The About-section video: it streams a sample clip. To use
    the school's own video, replace the <source src="..."> line
    in index.html with your video file, e.g.
    <source src="videos/school.mp4" type="video/mp4">
  - "Check Result" and Popular Links buttons currently point
    nowhere (#). When you have a results portal, replace the #
    with the portal address.

------------------------------------------------------------
7. CHANGING THE ADMIN PASSCODE
------------------------------------------------------------
The passcode is iriebe2026. To change it:
1. Open admin.html in Notepad / any text editor.
2. Find the line:      var PASSCODE   = 'iriebe2026';
3. Replace the word between the quotes with your new passcode.
4. Save the file.
Keep it private — anyone with the passcode can change the site.

------------------------------------------------------------
8. TROUBLESHOOTING
------------------------------------------------------------
- Site has no styling (plain text, no colors):
    styles.css is not beside index.html, or was renamed.
- Nothing moves (slideshow, FAQ, buttons dead):
    script.js is not beside index.html, or was renamed.
- Logo shows a green box instead:
    images folder missing, or file not named school-logo.png.
- Dashboard changes do not show on another computer/phone:
    that is normal until you upload content.json to the host.
- Photos or video not loading:
    they stream from the internet — check the connection.
- Everything else:
    open the browser's console (press F12) and read the error,
    or contact your developer.

------------------------------------------------------------
9. QUICK FACTS
------------------------------------------------------------
- Works on phones, tablets and computers (fully responsive).
- No internet? Layout and text still work; online photos and
  the video simply will not load.
- Respects visitors who prefer reduced motion.
- No database, no server code — pure HTML, CSS and JavaScript,
  so it is cheap to host and hard to break.

Built by New Technologies — thank you for choosing us.
============================================================
