-- محرك الخريطة: leaflet (افتراضي) | arcgis (ArcGIS JavaScript SDK)
ALTER TABLE sys_company_settings
    ADD COLUMN gps_map_engine VARCHAR(16) NOT NULL DEFAULT 'leaflet';
