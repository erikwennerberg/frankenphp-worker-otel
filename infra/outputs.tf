output "public_ip" {
  value = aws_instance.app.public_ip
}

output "public_dns" {
  value = aws_instance.app.public_dns
}

output "app_url" {
  value = "http://${aws_instance.app.public_ip}:8080"
}

output "ssh_command" {
  value = "ssh -i ${local_sensitive_file.private_key.filename} ubuntu@${aws_instance.app.public_ip}"
}
