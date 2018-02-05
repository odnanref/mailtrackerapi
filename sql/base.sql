CREATE TABLE mailtracker (
    id BIGINT primary key auto_increment,
    name varchar(254),
    datein timestamp,
    datelast timestamp,
    image varchar(254),
    uuid varchar(254)
);

CREATE TABLE mailtracker_log(
    id BIGINT primary key auto_increment,
    uuid varchar(254),
    mailtracker_id bigint,
    useragent varchar(254),
    remoteip varchar(254),
    language varchar(254),
    charset varchar(254),
    datein timestamp
);
