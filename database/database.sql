CREATE DATABASE IF NOT EXISTS sim_dokim CHARACTER SET utf8mb4;
USE sim_dokim;
DROP TABLE IF EXISTS pengeluaran, penerimaan, dokumen, users;
CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY, nama VARCHAR(80) NOT NULL, username VARCHAR(40) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, role ENUM('admin','superadmin') NOT NULL DEFAULT 'admin', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE dokumen(id INT AUTO_INCREMENT PRIMARY KEY, kode VARCHAR(20) UNIQUE NOT NULL, nama VARCHAR(100) NOT NULL, satuan ENUM('BUKU','LEMBAR') DEFAULT 'LEMBAR', harga BIGINT DEFAULT 0, stok INT DEFAULT 0, rusak INT DEFAULT 0, terbit INT DEFAULT 0, seri_aktif VARCHAR(60) DEFAULT '-', seri_simpan VARCHAR(60) DEFAULT '-');
CREATE TABLE penerimaan(id INT AUTO_INCREMENT PRIMARY KEY, tanggal DATE NOT NULL, dokumen_id INT NOT NULL, jumlah INT NOT NULL, no_ba VARCHAR(60) NOT NULL, seri_awal VARCHAR(30), seri_akhir VARCHAR(30), catatan TEXT, user_id INT, status ENUM('Terverifikasi','Pending') DEFAULT 'Pending', FOREIGN KEY(dokumen_id) REFERENCES dokumen(id), FOREIGN KEY(user_id) REFERENCES users(id));
CREATE TABLE pengeluaran(id INT AUTO_INCREMENT PRIMARY KEY, tanggal DATE NOT NULL, dokumen_id INT NOT NULL, jumlah INT NOT NULL, tujuan VARCHAR(80) NOT NULL, seri_awal VARCHAR(30), seri_akhir VARCHAR(30), no_ba VARCHAR(60) NOT NULL, user_id INT, FOREIGN KEY(dokumen_id) REFERENCES dokumen(id), FOREIGN KEY(user_id) REFERENCES users(id));

-- Login: superadmin / Super123!   |   admin / Admin123!
INSERT INTO users(nama,username,password,role) VALUES
('Super Admin','superadmin','$2y$10$w7LfeoPx.S8B6O3d0j.FeuigXq92bPfC6J11mrqrSdKufAAZ0CVe6','superadmin'),
('Admin DOKIM','admin','$2y$10$iEG2ivPxYN6khz5rmrrm8OhRqkJPe5te7N/Gc.fu6VsIqrTRElZci','admin');

INSERT INTO dokumen(kode,nama,satuan,harga,stok,rusak,terbit,seri_aktif,seri_simpan) VALUES
('EP-48','E-Paspor 48 Hal','BUKU',202829,25000,5,1200,'A00001 - B5000','B5001 - E25000'),
('PB-48','Paspor Biasa 48 Hal','BUKU',202829,2866,12,300,'P12001 - P14000','P14001 - P14866'),
('SPLP-W','SPLP WNA','BUKU',31000,50,0,10,'W0001 - W0050','-'),
('SKM','Skim','LEMBAR',160,160,0,0,'S001 - S100','S101 - S160'),
('EK-TP','E-KITAP','LEMBAR',24999,0,2,45,'-','-'),
('KAF','Kartu Affidavit','LEMBAR',1750,578,1,80,'A201 - A500','A501 - A778'),
('SABG','Sertifikat ABG','LEMBAR',650,1611,3,150,'ABG1001 - ABG2000','ABG2001 - ABG2611'),
('FTTABG','Form Tanda Terima ABG','LEMBAR',130,610,0,90,'F01 - F400','F401 - F610'),
('FPABG','Form Pendaftaran ABG','LEMBAR',130,500,0,50,'FP01 - FP300','FP301 - FP500'),
('SKR-RL','Stiker Kedatangan Roll','LEMBAR',72356,324,0,76,'R01 - R100','R101 - R324'),
('SVOA-30','Stiker VOA 30 Hari','LEMBAR',7300,81142,8,4500,'V00001 - V20000','V20001 - V81142'),
('PRD-28','Perdim 28','LEMBAR',147,0,0,120,'-','-'),
('PRD-24','Perdim 24','LEMBAR',147,4000,1,430,'P24-001 - P24-2000','P24-2001 - P24-4000'),
('PRD-25','Perdim 25','LEMBAR',147,4000,0,300,'P25-001 - P25-2000','P25-2001 - P25-4000'),
('PRD-27','Perdim 27','LEMBAR',147,13500,2,1100,'P27-0001 - P27-5000','P27-5001 - P27-13500'),
('SITG','Stiker Izin Tinggal','LEMBAR',71775,60000,5,3800,'S00001 - S20000','S20001 - S60000'),
('VKSK-500','Voucher VKSK 500K','LEMBAR',700,0,0,50,'-','-');

INSERT INTO penerimaan(tanggal,dokumen_id,jumlah,no_ba,seri_awal,seri_akhir,user_id,status) VALUES
('2026-01-15',1,10000,'BA/101/IM/I/2026','A00001','A10000',2,'Terverifikasi'),
('2026-01-14',11,30000,'BA/102/IM/I/2026','V00001','V30000',2,'Terverifikasi'),
('2026-01-12',15,5000,'BA/098/IM/I/2026','P27-0001','P27-5000',2,'Terverifikasi'),
('2026-01-10',16,15000,'BA/095/IM/I/2026','S00001','S15000',2,'Pending'),
('2026-01-08',2,2000,'BA/091/IM/I/2026','P12001','P14000',2,'Terverifikasi'),
('2026-01-05',7,1000,'BA/087/IM/I/2026','ABG1001','ABG2000',2,'Terverifikasi');
INSERT INTO pengeluaran(tanggal,dokumen_id,jumlah,tujuan,seri_awal,seri_akhir,no_ba,user_id) VALUES
('2026-01-15',11,358,'TPI','V00001','V00358','BA/OUT/101/IM/I/2026',2),
('2026-01-15',17,358,'TPI','VK001','VK358','BA/OUT/102/IM/I/2026',2),
('2026-01-14',10,7,'TPI','R01','R07','BA/OUT/103/IM/I/2026',2),
('2026-01-14',15,50,'Doklan','P27-01','P27-50','BA/OUT/104/IM/I/2026',2),
('2026-01-13',1,100,'Kantor Imigrasi','EP-001','EP-100','BA/OUT/105/IM/I/2026',2),
('2026-01-12',2,25,'Kantor Imigrasi','PB-01','PB-25','BA/OUT/106/IM/I/2026',2);
