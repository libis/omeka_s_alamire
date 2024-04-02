<h1>Omeka-S in docker</h1>

Volumes:
- The config folder (containing database.ini and local.config.php)
- The files folder as this is a working folder

Configuration:  
- Rename database.ini.dist to database.ini and enter mysql credentials  
- For a fresh install with a new database rename files.dist to files, otherwise copy from the appropriate installation
- If you are using an existing database you will have to download or copy the matching files directory from the server  

Use `docker-compose up -d` to start the container
