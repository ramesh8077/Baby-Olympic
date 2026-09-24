import os, re
d = "/home/ramesh-chandra/Desktop/Baby Olympich shidhant /baby olympic (3)/baby olympic/Baby Olympic"
for f in os.listdir(d):
    if f.endswith(".html"):
        p = os.path.join(d, f)
        with open(p, "r") as file:
            c = file.read()
        
        # The file literally has href=\"/something\"
        # We want to replace it with href="/something"
        c = c.replace('href=\\"/', 'href="/')
        c = c.replace('href=\\"', 'href="')
        c = c.replace('\\">', '">')
        c = c.replace('\\" ', '" ')
        
        with open(p, "w") as file:
            file.write(c)
