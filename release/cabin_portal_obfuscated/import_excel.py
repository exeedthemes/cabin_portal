import openpyxl
import sqlite3
import datetime
import os
import re

def parse_excel_date(val):
    if val is None:
        return '2024-01-01 12:00:00'
    if isinstance(val, datetime.datetime):
        return val.strftime('%Y-%m-%d %H:%M:%S')
    if isinstance(val, datetime.date):
        return datetime.datetime.combine(val, datetime.time.min).strftime('%Y-%m-%d %H:%M:%S')
        
    s = str(val).strip()
    if not s or s.lower() in ['no info', 'n/a', 'unknown', 'noinfo']:
        return '2024-01-01 12:00:00'
        
    # Try standard formats
    for fmt in [
        '%Y-%m-%d %H:%M:%S', '%Y-%m-%d', '%d-%b-%y', '%d-%b-%Y',
        '%d %B %Y', '%d %b %Y', '%d.%m.%Y', '%d-%m-%Y', '%Y/%m/%d'
    ]:
        try:
            return datetime.datetime.strptime(s, fmt).strftime('%Y-%m-%d %H:%M:%S')
        except ValueError:
            pass
            
    # Try case-insensitive conversions or cleaning
    m_my = re.match(r'^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[-\s\.]*(\d{2,4})$', s, re.IGNORECASE)
    if m_my:
        months = {'jan':1, 'feb':2, 'mar':3, 'apr':4, 'may':5, 'jun':6, 'jul':7, 'aug':8, 'sep':9, 'oct':10, 'nov':11, 'dec':12}
        m = months[m_my.group(1).lower()]
        y = int(m_my.group(2))
        if y < 100:
            y += 2000
        return f'{y:04d}-{m:02d}-01 00:00:00'
        
    m_dj = re.match(r'^(\d{1,2})[-\s\.]*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|dez|march|april|sept)$', s, re.IGNORECASE)
    if m_dj:
        months = {'jan':1, 'feb':2, 'mar':3, 'march':3, 'apr':4, 'april':4, 'may':5, 'jun':6, 'jul':7, 'aug':8, 'sep':9, 'sept':9, 'oct':10, 'nov':11, 'dec':12, 'dez':12}
        d = int(m_dj.group(1))
        m = months[m_dj.group(2).lower()]
        return f'2024-{m:02d}-{d:02d} 00:00:00'
        
    m_dmy = re.match(r'^(\d{1,2})[-\s\.]*(jan|feb|mar|march|apr|april|may|jun|jul|july|aug|sep|sept|oct|nov|dec|dez)[-\s\.]*(\d{2,4})$', s, re.IGNORECASE)
    if m_dmy:
        months = {'jan':1, 'feb':2, 'mar':3, 'march':3, 'apr':4, 'april':4, 'may':5, 'jun':6, 'jul':7, 'july':7, 'aug':8, 'sep':9, 'sept':9, 'oct':10, 'nov':11, 'dec':12, 'dez':12}
        d = int(m_dmy.group(1))
        m = months[m_dmy.group(2).lower()]
        y = int(m_dmy.group(3))
        if y < 100:
            y += 2000
        return f'{y:04d}-{m:02d}-{d:02d} 00:00:00'

    # fallback
    return '2024-01-01 12:00:00'

def normalize_tag(tag):
    if not tag:
        return ""
    tag = str(tag).strip().upper()
    # Remove all whitespace
    tag = re.sub(r'\s+', '', tag)
    # Match ID-?(\d+.*)
    m_id = re.match(r'^ID-?(\d+.*)$', tag)
    if m_id:
        return f"ID-{m_id.group(1)}"
    # Match PP-?ID-?(\d+.*) or PP-?(\d+.*)
    tag_clean = tag.replace('ID', '')
    m_pp = re.match(r'^PP-?(\d+.*)$', tag_clean)
    if m_pp:
        return f"PP-{m_pp.group(1)}"
    return tag

def ser(obj):
    if isinstance(obj, datetime.datetime):
        return obj.strftime('%Y-%m-%d %H:%M:%S')
    return str(obj) if obj is not None else ""

script_dir = os.path.dirname(os.path.abspath(__file__))
xlsx_path = os.path.join(script_dir, '../LOFO Items Tracking ORIGINAL(Automatisch wiederhergestellt).xlsx')
db_path = os.path.join(script_dir, 'cabin_db.sqlite')

if not os.path.exists(xlsx_path):
    print(f"Error: {xlsx_path} not found")
    exit(1)

wb = openpyxl.load_workbook(xlsx_path)

conn = sqlite3.connect(db_path)
cursor = conn.cursor()

# Ensure deleted tracking tables exist
cursor.execute("CREATE TABLE IF NOT EXISTS deleted_items (tag_no TEXT PRIMARY KEY)")
cursor.execute("CREATE TABLE IF NOT EXISTS deleted_airlines (code TEXT PRIMARY KEY)")

# Load deleted items and airlines sets
cursor.execute("SELECT tag_no FROM deleted_items")
deleted_items = {normalize_tag(row[0]) for row in cursor.fetchall() if row[0]}

cursor.execute("SELECT code FROM deleted_airlines")
deleted_airlines = {str(row[0]).strip().upper() for row in cursor.fetchall() if row[0]}

# Clear existing items
cursor.execute("DELETE FROM items")

count = 0

# 1. Import from 'Lost & Found Items' sheet
if 'Lost & Found Items' in wb.sheetnames:
    sheet = wb['Lost & Found Items']
    rows = list(sheet.iter_rows(values_only=True))
    data = rows[1:] # skip header
    for r in data:
        if not any(r): continue # Skip empty rows
        
        # Mapping
        # 0: Tag No / Index (e.g. ID-0001)
        # 1: Description
        # 2: Airline
        # 3: Flt No.
        # 4: Seat No.
        # 5: Date
        # 6: Pax Name
        # 7: Pax Contact no.
        # 8: Other Info.
        # 9: User Comments
        # 10: Delivery Info.
        # 11: Comments
        
        orig_tag = str(r[0]).strip() if r[0] else ""
        tag_no = normalize_tag(orig_tag) if orig_tag else f"LF-{count+1:04d}"
        if tag_no in deleted_items:
            continue
            
        item_description = str(r[1]).strip() if r[1] else "Unknown Item"
        airline = str(r[2]).strip() if r[2] else ""
        airline_code = airline.upper().replace('Ê', 'E')
        # Extract letters only for airline code in case it has flight number like BA926
        m_code = re.match(r'^([A-Z0-9]{2})', airline_code)
        if m_code:
            airline_code = m_code.group(1)
            
        airline_mapping = {
            'EY': 'Etihad Airways',
            'EI': 'Aer Lingus',
            'VY': 'Vueling',
            'DY': 'Norwegian Air',
            'WF': 'Widerøe',
            'VN': 'Vietnam Airlines',
            'IB': 'Iberia',
            'UX': 'Air Europa',
            'TU': 'Tunisair',
            'BA': 'British Airways'
        }
        
        if airline_code in airline_mapping and airline_code not in deleted_airlines:
            airline_name = airline_mapping[airline_code]
            # Ensure exists in db
            cursor.execute("SELECT COUNT(*) FROM airlines WHERE code = ?", (airline_code,))
            if cursor.fetchone()[0] == 0:
                logo = f"https://www.gstatic.com/flights/airline_logos/70px/{airline_code}.png"
                domain = f"{airline_name.lower().replace(' ', '')}.com"
                cursor.execute("INSERT OR IGNORE INTO airlines (name, code, logo, domain) VALUES (?, ?, ?, ?)",
                               (airline_name, airline_code, logo, domain))
        
        flt_no = str(r[3]).strip() if r[3] else ""
        seat_no = str(r[4]).strip() if r[4] else ""
        
        # Parse date
        created_at = parse_excel_date(r[5])
                    
        pax_name = str(r[6]).strip() if r[6] else ""
        pax_contact = str(r[7]).strip() if r[7] else ""
        other_info_col = str(r[8]).strip() if r[8] else ""
        user_comments = str(r[9]).strip() if r[9] else ""
        delivery_info = str(r[10]).strip() if r[10] else ""
        comments_col = str(r[11]).strip() if r[11] else ""
        
        # Separate Flight and Seat
        # Store separate flight in other_info
        flight = f"{airline} {flt_no}".strip()
        if not flight:
            flight = other_info_col
        elif other_info_col:
            flight = f"{flight} ({other_info_col})"
            
        # Store separate seat in comments
        seat = seat_no
        if comments_col:
            if seat:
                user_comments = f"{comments_col} | {user_comments}".strip(" |")
            else:
                seat = comments_col
                
        # Status calculation: check both delivery_info and comments_col (comments)
        status = 'Found'
        text_to_check = []
        if delivery_info:
            text_to_check.append(delivery_info.lower())
        if comments_col:
            text_to_check.append(comments_col.lower())
            
        full_text = " ".join(text_to_check)
        if any(x in full_text for x in ['delivered', 'done', 'handed over', 'returned', 'collected', 'pax', 'sent', 'picked up', 'p/up', 'colleted', 'dlvd']):
            status = 'Delivered'
        elif any(x in full_text for x in ['disposed', 'destroyed', 'scrapped', 'waste']):
            status = 'Disposed'
                
        cursor.execute("""
            INSERT INTO items (tag_no, item_description, contents, pax_name, pax_contact_no, other_info, user_comments, delivery_info, comments, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (tag_no, item_description, "", pax_name, pax_contact, flight, user_comments, delivery_info, seat, status, created_at))
        count += 1

# 2. Import from 'ID&Passports' sheet
if 'ID&Passports' in wb.sheetnames:
    sheet = wb['ID&Passports']
    rows = list(sheet.iter_rows(values_only=True))
    data = rows[1:] # skip header
    for r in data:
        if not any(r): continue # Skip empty rows
        
        # Mapping
        # 0: Tag No (e.g. ID-0001)
        # 1: Item Description
        # 2: Contents
        # 3: Pax Name
        # 4: Pax address
        # 5: Pax Contact no.
        # 6: Other Info.
        # 7: User Comments
        # 8: Delivery Info.
        # 9: Comments
        
        orig_tag = str(r[0]).strip() if r[0] else ""
        if orig_tag:
            normalized = normalize_tag(orig_tag)
            if normalized.startswith("PP-"):
                tag_no = normalized
            else:
                tag_no = f"PP-{normalized.replace('ID-', '')}"
        else:
            tag_no = f"PP-{count+1:04d}"
            
        if tag_no in deleted_items:
            continue
            
        item_description = str(r[1]).strip() if r[1] else "Unknown ID/Passport"
        contents = str(r[2]).strip() if r[2] else ""
        pax_name = str(r[3]).strip() if r[3] else ""
        pax_address = str(r[4]).strip() if r[4] else ""
        pax_contact = str(r[5]).strip() if r[5] else ""
        other_info = str(r[6]).strip() if r[6] else ""
        user_comments = str(r[7]).strip() if r[7] else ""
        delivery_info = str(r[8]).strip() if r[8] else ""
        comments = str(r[9]).strip() if r[9] else ""
        
        # Status calculation: check both delivery_info and comments
        status = 'Found'
        text_to_check = []
        if delivery_info:
            text_to_check.append(delivery_info.lower())
        if comments:
            text_to_check.append(comments.lower())
            
        full_text = " ".join(text_to_check)
        if any(x in full_text for x in ['delivered', 'done', 'handed over', 'returned', 'collected', 'pax', 'sent', 'picked up', 'p/up', 'colleted', 'dlvd']):
            status = 'Delivered'
        elif any(x in full_text for x in ['disposed', 'destroyed', 'scrapped', 'waste']):
            status = 'Disposed'
                
        created_at = '2024-01-01 12:00:00'
        
        cursor.execute("""
            INSERT INTO items (tag_no, item_description, contents, pax_name, pax_address, pax_contact_no, other_info, user_comments, delivery_info, comments, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (tag_no, item_description, contents, pax_name, pax_address, pax_contact, other_info, user_comments, delivery_info, comments, status, created_at))
        count += 1

conn.commit()
conn.close()
print(f"Successfully imported {count} items from both sheets.")
